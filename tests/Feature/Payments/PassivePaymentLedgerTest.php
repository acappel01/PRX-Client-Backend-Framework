<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Data\Payments\PaymentIntentData;
use App\Data\Payments\PaymentOperationData;
use App\Data\Payments\PaymentUncertaintyData;
use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Enums\Payments\PaymentOperationState;
use App\Enums\Payments\PaymentUncertaintyReason;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PassivePaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Order $order;

    private MerchantAccount $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        // No action may even resolve a money-executing driver, including SDK
        // drivers whose network calls do not pass through Laravel's HTTP facade.
        $this->mock(PaymentGatewayManager::class, function ($mock): void {
            $mock->shouldNotReceive('forAccount');
            $mock->shouldNotReceive('forAccountId');
            $mock->shouldNotReceive('driver');
            $mock->shouldNotReceive('default');
        });
        $this->customer = Customer::factory()->create();
        $this->order = Order::factory()->create(['customer_id' => $this->customer->id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $this->merchant = MerchantAccount::factory()->create();
    }

    private function intentData(array $extra = []): PaymentIntentData
    {
        return new PaymentIntentData(...($extra + [
            'uuid' => (string) Str::uuid(), 'order_id' => $this->order->id,
            'customer_id' => $this->customer->id, 'merchant_account_id' => $this->merchant->id,
            'gateway_provider' => GatewayProvider::AuthorizeNet, 'environment' => GatewayEnvironment::Sandbox,
            'amount_minor' => 2500, 'currency' => 'USD', 'executor_key' => 'local.checkout',
        ]));
    }

    private function operationData(PaymentIntent $intent, array $extra = []): PaymentOperationData
    {
        return new PaymentOperationData(...($extra + [
            'uuid' => (string) Str::uuid(), 'intent_uuid' => $intent->uuid,
            'purpose' => PaymentOperationPurpose::Sale, 'amount_minor' => 2500,
            'executor_key' => 'local.checkout',
        ]));
    }

    private function expectInvalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected payment scope/idempotency validation to reject the request.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_intent_and_operation_replay_without_money_or_order_projection(): void
    {
        $orderBefore = $this->order->fresh()->getAttributes();
        $data = $this->intentData();
        $intent = app(PreparePaymentIntentAction::class)->execute($data);
        $this->assertSame($intent->id, app(PreparePaymentIntentAction::class)->execute($data)->id);
        $operationData = $this->operationData($intent);
        $operation = app(PreparePaymentOperationAction::class)->execute($operationData);
        $this->assertSame($operation->id, app(PreparePaymentOperationAction::class)->execute($operationData)->id);
        $this->assertSame(PaymentOperationState::Prepared, $operation->state);
        $this->assertSame(2500, $intent->amount_minor);
        $this->assertSame($orderBefore, $this->order->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('payment_operations', 1);
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_same_intent_key_conflicts_on_customer_order_merchant_environment_amount_or_executor(): void
    {
        $data = $this->intentData();
        app(PreparePaymentIntentAction::class)->execute($data);
        foreach ([['amount_minor' => 2499], ['customer_id' => $this->customer->id + 1], ['order_id' => $this->order->id + 1],
            ['merchant_account_id' => $this->merchant->id + 1], ['environment' => GatewayEnvironment::Production],
            ['gateway_provider' => GatewayProvider::Nmi], ['executor_key' => 'provider.elsewhere'], ['currency' => 'EUR']] as $change) {
            $changed = clone $data;
            foreach ($change as $key => $value) {
                $changed->$key = $value;
            }
            $this->expectInvalid(fn () => app(PreparePaymentIntentAction::class)->execute($changed));
        }
        $this->assertDatabaseCount('payment_intents', 1);
    }

    public function test_new_intent_requires_current_matching_order_customer_merchant_and_currency(): void
    {
        $otherCustomer = Customer::factory()->create();
        foreach ([['customer_id' => $otherCustomer->id], ['environment' => GatewayEnvironment::Production], ['currency' => 'EUR'],
            ['amount_minor' => 0], ['amount_minor' => -1], ['amount_minor' => 1000000000000], ['currency' => 'usd'],
            ['executor_key' => 'https://example.test'], ['uuid' => 'not-a-uuid']] as $change) {
            $this->expectInvalid(fn () => app(PreparePaymentIntentAction::class)->execute($this->intentData($change)));
        }
        $this->merchant->update(['is_active' => false]);
        $this->expectInvalid(fn () => app(PreparePaymentIntentAction::class)->execute($this->intentData()));
        $this->assertDatabaseCount('payment_intents', 0);
    }

    public function test_merchant_credential_drift_blocks_new_operations_but_preserves_historical_replay(): void
    {
        $data = $this->intentData();
        $intent = app(PreparePaymentIntentAction::class)->execute($data);
        $operationData = $this->operationData($intent);
        $operation = app(PreparePaymentOperationAction::class)->execute($operationData);
        $this->merchant->update(['authnet_transaction_key' => 'rotated-synthetic-key']);
        $this->assertSame($intent->id, app(PreparePaymentIntentAction::class)->execute($data)->id);
        $this->assertSame($operation->id, app(PreparePaymentOperationAction::class)->execute($operationData)->id);
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent)));
        $this->assertStringNotContainsString('rotated-synthetic-key', json_encode($intent->getAttributes()));
        $this->assertArrayNotHasKey('merchant_binding_fingerprint', $intent->toArray());
    }

    public function test_order_amount_or_owner_drift_blocks_new_operations(): void
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $this->order->update(['total_amount' => '26.00']);
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent)));
        $this->order->update(['total_amount' => '25.00', 'customer_id' => Customer::factory()->create()->id]);
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent)));
        $this->assertDatabaseCount('payment_operations', 0);
    }

    public function test_conflicting_legacy_account_is_rejected_and_detached_account_blocks_new_operations(): void
    {
        $account = Patient::factory()->create();
        $this->order->update(['patient_id' => $account->id]);
        $this->expectInvalid(fn () => app(PreparePaymentIntentAction::class)->execute($this->intentData()));
        $this->customer->forceFill(['portal_account_id' => $account->id])->save();
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $this->customer->forceFill(['portal_account_id' => null])->save();
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent)));
        // Even removing both pointers cannot silently rebind this historical intent.
        $this->order->update(['patient_id' => null]);
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent)));
    }

    public function test_changed_priced_items_block_new_operations_even_when_order_total_is_unchanged(): void
    {
        $item = $this->order->items()->create(['name' => 'Synthetic item', 'sku' => 'item-a', 'quantity' => 1, 'unit_price' => '25.00', 'line_total' => '25.00']);
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $item->update(['quantity' => 2, 'unit_price' => '12.50']);
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent)));
        $this->assertStringNotContainsString('Synthetic item', json_encode($intent->getAttributes()));
    }

    public function test_multiple_typed_operations_keep_original_lineage_and_explicit_executor(): void
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $auth = app(PreparePaymentOperationAction::class)->execute($this->operationData($intent, ['purpose' => PaymentOperationPurpose::Authorize]));
        $capture = app(PreparePaymentOperationAction::class)->execute($this->operationData($intent, [
            'purpose' => PaymentOperationPurpose::Capture, 'original_operation_uuid' => $auth->uuid, 'executor_key' => 'provider.fulfillment',
        ]));
        $refund = app(PreparePaymentOperationAction::class)->execute($this->operationData($intent, [
            'purpose' => PaymentOperationPurpose::Refund, 'original_operation_uuid' => $capture->uuid, 'amount_minor' => 1000,
        ]));
        $this->assertSame($auth->id, $capture->original_operation_id);
        $this->assertSame($capture->id, $refund->original_operation_id);
        $this->assertSame('provider.fulfillment', $capture->executor_key);
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('payment_operations', 3);
        $this->assertSame([PaymentOperationState::Prepared], PaymentOperation::all()->pluck('state')->unique()->values()->all());
    }

    public function test_wrong_original_intent_type_and_excess_amount_are_rejected(): void
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $other = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $sale = app(PreparePaymentOperationAction::class)->execute($this->operationData($other));
        foreach ([['purpose' => PaymentOperationPurpose::Refund, 'original_operation_uuid' => $sale->uuid],
            ['purpose' => PaymentOperationPurpose::Capture], ['amount_minor' => 2501],
            ['purpose' => PaymentOperationPurpose::Sale, 'original_operation_uuid' => $sale->uuid]] as $change) {
            $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent, $change)));
        }
    }

    public function test_changed_operation_key_never_changes_original_preparation(): void
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $data = $this->operationData($intent);
        $operation = app(PreparePaymentOperationAction::class)->execute($data);
        foreach ([['amount_minor' => 2400], ['purpose' => PaymentOperationPurpose::Authorize], ['executor_key' => 'external.worker']] as $change) {
            $changed = clone $data;
            foreach ($change as $key => $value) {
                $changed->$key = $value;
            }
            $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($changed));
        }
        $this->assertSame(2500, $operation->fresh()->amount_minor);
    }

    public function test_uncertainty_is_encrypted_immutable_replayable_and_blocks_new_operations(): void
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $data = $this->operationData($intent);
        $operation = app(PreparePaymentOperationAction::class)->execute($data);
        $evidence = new PaymentUncertaintyData($operation->uuid, PaymentUncertaintyReason::TransportTimeout, CarbonImmutable::parse('2026-09-14T10:00:00.123456Z'), 'synthetic-transaction', 'synthetic-original');
        $this->merchant->update(['environment' => GatewayEnvironment::Production]);
        $uncertain = app(RecordPaymentUncertaintyAction::class)->execute($evidence);
        $this->assertSame(PaymentOperationState::Uncertain, $uncertain->state);
        $this->assertSame($uncertain->id, app(RecordPaymentUncertaintyAction::class)->execute($evidence)->id);
        $this->assertSame(PaymentOperationState::Uncertain, app(PreparePaymentOperationAction::class)->execute($data)->state);
        $this->assertStringNotContainsString('synthetic-transaction', $uncertain->getRawOriginal('uncertainty_evidence'));
        $this->assertArrayNotHasKey('uncertainty_evidence', $uncertain->toArray());
        $changed = clone $evidence;
        $changed->observed_at = $changed->observed_at->addMicrosecond();
        $this->expectInvalid(fn () => app(RecordPaymentUncertaintyAction::class)->execute($changed));
        $changed = clone $evidence;
        $changed->reason = PaymentUncertaintyReason::UnverifiedResponse;
        $this->expectInvalid(fn () => app(RecordPaymentUncertaintyAction::class)->execute($changed));
        // Restore merchant to isolate the unresolved-operation guard.
        $this->merchant->update(['environment' => GatewayEnvironment::Sandbox]);
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute($this->operationData($intent)));
        $this->assertDatabaseCount('payment_operations', 1);
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_uncertainty_rejects_unbounded_or_structured_remote_payloads(): void
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $operation = app(PreparePaymentOperationAction::class)->execute($this->operationData($intent));
        foreach ([str_repeat('x', 129), '{"card":"raw"}', 'https://provider.test/token?secret=raw', "id\nvalue"] as $reference) {
            $this->expectInvalid(fn () => app(RecordPaymentUncertaintyAction::class)->execute(new PaymentUncertaintyData(
                $operation->uuid, PaymentUncertaintyReason::UnverifiedResponse, CarbonImmutable::now(), $reference,
            )));
        }
        $this->assertSame(PaymentOperationState::Prepared, $operation->fresh()->state);
    }

    public function test_intent_and_operation_identity_cannot_be_edited_or_deleted(): void
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $operation = app(PreparePaymentOperationAction::class)->execute($this->operationData($intent));
        foreach ([fn () => $intent->update(['amount_minor' => 1]), fn () => $intent->delete(),
            fn () => $operation->update(['amount_minor' => 1]), fn () => $operation->delete(),
            fn () => $operation->fresh()->update(['state' => PaymentOperationState::Uncertain])] as $change) {
            try {
                $change();
                $this->fail('Expected immutable ledger protection.');
            } catch (LogicException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }
}
