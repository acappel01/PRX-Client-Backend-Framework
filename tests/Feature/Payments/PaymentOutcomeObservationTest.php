<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordPaymentOutcomeObservationAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Data\Payments\PaymentIntentData;
use App\Data\Payments\PaymentOperationData;
use App\Data\Payments\PaymentOutcomeObservationData;
use App\Data\Payments\PaymentUncertaintyData;
use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Enums\Payments\PaymentOperationState;
use App\Enums\Payments\PaymentUncertaintyReason;
use App\Enums\Payments\ReportedPaymentOutcome;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PaymentOutcomeObservationTest extends TestCase
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

    private function observationData(): PaymentOutcomeObservationData
    {
        $intent = app(PreparePaymentIntentAction::class)->execute($this->intentData());
        $operation = app(PreparePaymentOperationAction::class)->execute($this->operationData($intent));

        return new PaymentOutcomeObservationData(
            uuid: (string) Str::uuid(), operation_uuid: $operation->uuid,
            merchant_account_uuid: $this->merchant->uuid, gateway_provider: GatewayProvider::AuthorizeNet,
            environment: GatewayEnvironment::Sandbox, source_key: 'synthetic.followup',
            reported_outcome: ReportedPaymentOutcome::Captured,
            observed_at: CarbonImmutable::parse('2026-09-14T14:20:30.123456Z'),
            gateway_transaction_reference: 'synthetic_tx_1', source_event_reference: 'synthetic_event_1',
            reported_amount_minor: 2500, reported_currency: 'USD',
        );
    }

    public function test_replay_retains_encrypted_hidden_evidence_without_financial_effects(): void
    {
        $data = $this->observationData();
        $before = $this->order->fresh()->getAttributes();
        $action = app(RecordPaymentOutcomeObservationAction::class);
        $observation = $action->execute($data);
        $uppercase = clone $data;
        $uppercase->uuid = strtoupper($data->uuid);
        $uppercase->operation_uuid = strtoupper($data->operation_uuid);
        $uppercase->merchant_account_uuid = strtoupper($data->merchant_account_uuid);
        $this->assertSame($observation->id, $action->execute($uppercase)->id);
        $this->assertSame('2026-09-14T14:20:30.123456Z', $observation->reported_evidence['observed_at']);
        $this->assertSame('captured', $observation->reported_evidence['reported_outcome']);
        $raw = DB::table('payment_outcome_observations')->value('reported_evidence');
        $this->assertStringNotContainsString('synthetic_tx_1', $raw);
        $this->assertArrayNotHasKey('reported_evidence', $observation->toArray());
        $this->assertArrayNotHasKey('request_fingerprint', $observation->toArray());
        $this->assertSame(PaymentOperationState::Prepared, PaymentOperation::first()->state);
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_outcome_observations', 1);
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_every_changed_evidence_field_conflicts_under_same_identity(): void
    {
        $data = $this->observationData();
        $action = app(RecordPaymentOutcomeObservationAction::class);
        $action->execute($data);
        foreach ([
            'operation_uuid' => (string) Str::uuid(), 'merchant_account_uuid' => (string) Str::uuid(),
            'gateway_provider' => GatewayProvider::Nmi, 'environment' => GatewayEnvironment::Production,
            'source_key' => 'synthetic.other', 'reported_outcome' => ReportedPaymentOutcome::Settled,
            'observed_at' => $data->observed_at->addMicrosecond(),
            'gateway_transaction_reference' => 'synthetic_other',
            'original_gateway_transaction_reference' => 'synthetic_original',
            'source_event_reference' => 'synthetic_event_other', 'reported_amount_minor' => 2499,
            'reported_currency' => 'EUR',
        ] as $field => $value) {
            $changed = clone $data;
            $changed->$field = $value;
            $this->expectInvalid(fn () => $action->execute($changed));
        }
        $this->assertDatabaseCount('payment_outcome_observations', 1);
    }

    public function test_new_observation_requires_frozen_account_scope(): void
    {
        $data = $this->observationData();
        foreach (['merchant_account_uuid' => (string) Str::uuid(), 'gateway_provider' => GatewayProvider::Nmi,
            'environment' => GatewayEnvironment::Production] as $field => $value) {
            $changed = clone $data;
            $changed->$field = $value;
            $this->expectInvalid(fn () => app(RecordPaymentOutcomeObservationAction::class)->execute($changed));
        }
        $this->assertDatabaseCount('payment_outcome_observations', 0);
    }

    public function test_followup_survives_drift_and_never_resolves_uncertainty(): void
    {
        $data = $this->observationData();
        app(RecordPaymentUncertaintyAction::class)->execute(new PaymentUncertaintyData(
            $data->operation_uuid, PaymentUncertaintyReason::TransportTimeout, $data->observed_at,
        ));
        $before = PaymentOperation::first()->getAttributes();
        $originalKey = $this->merchant->authnet_transaction_key;
        $this->merchant->update(['authnet_transaction_key' => 'synthetic_rotated_key']);
        $this->order->update(['total_amount' => '30.00']);
        $action = app(RecordPaymentOutcomeObservationAction::class);
        $action->execute($data);
        $later = clone $data;
        $later->uuid = (string) Str::uuid();
        $later->reported_outcome = ReportedPaymentOutcome::Settled;
        $later->observed_at = $data->observed_at->addMinute();
        // Claims inconsistent with the obligation remain evidence, not money.
        $later->reported_amount_minor = 4000;
        $later->reported_currency = 'EUR';
        $action->execute($later);
        $this->assertSame($before, PaymentOperation::first()->getAttributes());
        // Restore commercial drift so uncertainty itself is the blocker.
        $this->order->update(['total_amount' => '25.00']);
        $this->merchant->update(['authnet_transaction_key' => $originalKey]);
        $this->expectInvalid(fn () => app(PreparePaymentOperationAction::class)->execute(
            $this->operationData(PaymentIntent::first()),
        ));
        $this->assertDatabaseCount('payment_outcome_observations', 2);
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_references_and_incomplete_amounts_are_rejected(): void
    {
        $data = $this->observationData();
        foreach ([['source_key' => ''], ['gateway_transaction_reference' => 'raw response body'],
            ['source_event_reference' => str_repeat('x', 129)], ['reported_amount_minor' => -1],
            ['reported_amount_minor' => 1000000000000], ['reported_amount_minor' => null],
            ['reported_currency' => null], ['reported_amount_minor' => 0, 'reported_currency' => null], ['reported_currency' => 'usd']] as $changes) {
            $changed = clone $data;
            foreach ($changes as $field => $value) {
                $changed->$field = $value;
            }
            $this->expectInvalid(fn () => app(RecordPaymentOutcomeObservationAction::class)->execute($changed));
        }
        $data->reported_amount_minor = null;
        $data->reported_currency = null;
        app(RecordPaymentOutcomeObservationAction::class)->execute($data);
        $this->assertDatabaseCount('payment_outcome_observations', 1);
    }

    public function test_equal_remote_references_on_distinct_accounts_are_not_merged(): void
    {
        $first = $this->observationData();
        app(RecordPaymentOutcomeObservationAction::class)->execute($first);
        $this->merchant = MerchantAccount::factory()->create();
        $second = $this->observationData();
        app(RecordPaymentOutcomeObservationAction::class)->execute($second);
        $this->assertSame($first->gateway_transaction_reference, $second->gateway_transaction_reference);
        $this->assertDatabaseCount('payment_outcome_observations', 2);
    }

    public function test_observations_reject_ordinary_updates_and_deletes(): void
    {
        $observation = app(RecordPaymentOutcomeObservationAction::class)->execute($this->observationData());
        try {
            $observation->update(['reported_evidence' => ['reported_outcome' => 'settled']]);
            $this->fail('Expected immutable evidence.');
        } catch (LogicException) {
            $this->assertSame('captured', $observation->fresh()->reported_evidence['reported_outcome']);
        }
        $this->expectException(LogicException::class);
        $observation->fresh()->delete();
    }
}
