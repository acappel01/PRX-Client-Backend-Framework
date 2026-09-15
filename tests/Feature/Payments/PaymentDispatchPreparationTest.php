<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreparePaymentDispatchAction;
use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Actions\Payments\ReservePaymentOperationReferenceAction;
use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Data\Payments\GatewayAccountBindingData;
use App\Data\Payments\PaymentDispatchPreparationData;
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
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PaymentDispatchPreparationTest extends TestCase
{
    private MerchantAccount $merchant;

    private Order $order;

    private GatewayAccountBinding $binding;

    private PaymentIntent $intent;

    protected function setUp(): void
    {
        parent::setUp();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->mock(PaymentGatewayManager::class, function ($mock): void {
            $mock->shouldNotReceive('forAccount', 'forAccountId', 'driver', 'default');
        });
        Http::preventStrayRequests();
        $customer = Customer::factory()->create();
        $this->order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $this->merchant = MerchantAccount::factory()->create();
        Http::fake(fn () => Http::response('<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>123</gatewayId><currencies><currency>USD</currency></currencies></getMerchantDetailsResponse>'));
        $this->binding = app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '123', 'USD'));
        $this->intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $customer->id, $this->merchant->id, GatewayProvider::AuthorizeNet, GatewayEnvironment::Sandbox, 2500, 'USD', 'local.checkout'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function reference(PaymentOperationPurpose $purpose = PaymentOperationPurpose::Sale, ?PaymentOperation $original = null): PaymentOperationReference
    {
        $operation = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $this->intent->uuid, $purpose, 2500, 'local.checkout', $original?->uuid));

        return app(ReservePaymentOperationReferenceAction::class)->execute($operation->uuid, $this->binding->id);
    }

    private function data(PaymentOperationReference $reference, array $changes = []): PaymentDispatchPreparationData
    {
        return new PaymentDispatchPreparationData(...($changes + ['uuid' => (string) Str::uuid(), 'payment_operation_reference_id' => $reference->id, 'executor_key' => 'local.checkout']));
    }

    private function prepare(PaymentDispatchPreparationData $data): PaymentDispatchPreparation
    {
        return app(PreparePaymentDispatchAction::class)->execute($data);
    }

    private function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected preparation refusal.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function uncertain(PaymentOperationReference $reference): void
    {
        $operation = PaymentOperation::findOrFail($reference->payment_operation_id);
        app(RecordPaymentUncertaintyAction::class)->execute(new PaymentUncertaintyData($operation->uuid, PaymentUncertaintyReason::TransportTimeout, CarbonImmutable::now()));
    }

    public function test_preparation_is_committed_immutable_exact_scope_not_dispatch_or_payment(): void
    {
        $reference = $this->reference();
        $data = $this->data($reference);
        $now = CarbonImmutable::parse('2026-09-15T04:00:00.123456Z');
        CarbonImmutable::setTestNow($now);
        try {
            $row = $this->prepare($data)->fresh();
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('2026-09-15T04:00:00.123456Z', $row->prepared_at->utc()->toISOString());
        $this->assertSame('prepared_only', $row->state);
        $this->assertSame($row->id, $this->prepare($data)->id);
        $this->assertSame($row->id, $this->prepare($this->data($reference, ['uuid' => strtoupper($data->uuid)]))->id);
        $snapshot = $row->prepared_scope;
        $this->assertSame($reference->reference, $snapshot['merchant_reference']);
        $this->assertSame($this->intent->uuid, $snapshot['intent_uuid']);
        $this->assertSame($this->merchant->uuid, $snapshot['merchant_account_uuid']);
        $this->assertSame($this->binding->canonical_account_key, $snapshot['canonical_account_key']);
        $this->assertSame('123', $snapshot['gateway_account_id']);
        $this->assertSame('sale', $snapshot['purpose']);
        $this->assertSame(2500, $snapshot['amount_minor']);
        $this->assertSame('USD', $snapshot['currency']);
        $this->assertSame('local.checkout', $snapshot['executor_key']);
        $this->assertNull($snapshot['original_operation_uuid']);
        $this->assertStringNotContainsString($reference->reference, DB::table('payment_dispatch_preparations')->value('prepared_scope'));
        $this->assertArrayNotHasKey('prepared_scope', $row->toArray());
        $this->assertArrayNotHasKey('executor_key', $row->toArray());
        $this->assertStringNotContainsString($this->merchant->authnet_transaction_key, json_encode($snapshot));
        $this->assertSame(PaymentOperationState::Prepared, PaymentOperation::find($reference->payment_operation_id)->state);
        $this->assertDatabaseCount('payment_dispatch_preparations', 1);
        $this->assertDatabaseCount('payment_outcome_observations', 0);
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_one_uuid_and_one_reference_cannot_describe_multiple_preparations(): void
    {
        $one = $this->reference();
        $two = $this->reference();
        $data = $this->data($one);
        $this->prepare($data);
        $this->invalid(fn () => $this->prepare($this->data($one)));
        $this->invalid(fn () => $this->prepare($this->data($two, ['uuid' => $data->uuid])));
        $this->invalid(fn () => $this->prepare($this->data($one, ['uuid' => $data->uuid, 'executor_key' => 'different.executor'])));
        $this->assertDatabaseCount('payment_dispatch_preparations', 1);
    }

    public function test_executor_must_match_existing_operation_and_caller_transaction_is_refused(): void
    {
        $reference = $this->reference();
        $this->invalid(fn () => $this->prepare($this->data($reference, ['executor_key' => 'other.executor'])));
        DB::transaction(fn () => $this->invalid(fn () => $this->prepare($this->data($reference))));
        $this->assertDatabaseCount('payment_dispatch_preparations', 0);
        Http::assertNothingSent();
    }

    public function test_uncertainty_blocks_new_preparation_across_same_intent_but_history_replays(): void
    {
        $one = $this->reference();
        $two = $this->reference();
        $three = $this->reference();
        $data = $this->data($one);
        $row = $this->prepare($data);
        $this->uncertain($one);
        $this->invalid(fn () => $this->prepare($this->data($two)));
        $this->invalid(fn () => $this->prepare($this->data($three)));
        $this->assertSame($row->id, $this->prepare($data)->id);
        $this->assertSame(PaymentOperationState::Uncertain, PaymentOperation::find($one->payment_operation_id)->state);
        $this->assertDatabaseCount('payment_dispatch_preparations', 1);
    }

    public function test_already_uncertain_operation_cannot_gain_dispatch_preparation(): void
    {
        $reference = $this->reference();
        $this->uncertain($reference);
        $this->invalid(fn () => $this->prepare($this->data($reference)));
        $this->assertDatabaseCount('payment_dispatch_preparations', 0);
    }

    public function test_current_credential_and_commercial_scope_drift_refuse_new_preparation(): void
    {
        $one = $this->reference();
        $two = $this->reference();
        $data = $this->data($one);
        $row = $this->prepare($data);
        $old = $this->merchant->authnet_transaction_key;
        $this->merchant->update(['authnet_transaction_key' => 'synthetic-rotated']);
        $this->invalid(fn () => $this->prepare($this->data($two)));
        $this->assertSame($row->id, $this->prepare($data)->id);
        $this->merchant->update(['authnet_transaction_key' => $old]);
        $this->order->update(['total_amount' => '26.00']);
        $this->invalid(fn () => $this->prepare($this->data($two)));
        $this->assertDatabaseCount('payment_dispatch_preparations', 1);
    }

    public function test_original_lineage_is_frozen_without_claiming_original_was_executed(): void
    {
        $authorization = $this->reference(PaymentOperationPurpose::Authorize);
        $original = PaymentOperation::findOrFail($authorization->payment_operation_id);
        $capture = $this->reference(PaymentOperationPurpose::Capture, $original);
        $row = $this->prepare($this->data($capture));
        $this->assertSame($original->uuid, $row->prepared_scope['original_operation_uuid']);
        $this->assertSame('authorize', $row->prepared_scope['original_purpose']);
        $this->assertSame(2500, $row->prepared_scope['original_amount_minor']);
        $this->assertSame($authorization->reference, $row->prepared_scope['original_merchant_reference']);
        $this->assertSame('capture', $row->prepared_scope['purpose']);
        $this->assertSame(PaymentOperationState::Prepared, $original->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_preparation_cannot_be_updated_or_deleted(): void
    {
        $row = $this->prepare($this->data($this->reference()));
        foreach ([fn () => $row->update(['state' => 'dispatched']), fn () => $row->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected immutable history guard.');
            } catch (LogicException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertSame('prepared_only', $row->fresh()->state);
    }
}
