<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\DispatchPreparedPaymentAction;
use App\Actions\Payments\PreparePaymentDispatchAction;
use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Actions\Payments\ReservePaymentOperationReferenceAction;
use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Contracts\Payments\PaymentDispatchLineageResolver;
use App\Contracts\Payments\PaymentDispatchTransport;
use App\Data\Payments\GatewayAccountBindingData;
use App\Data\Payments\PaymentDispatchOriginal;
use App\Data\Payments\PaymentDispatchPreparationData;
use App\Data\Payments\PaymentDispatchReceipt;
use App\Data\Payments\PaymentDispatchRequest;
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
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationReferenceScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PaymentDispatchBoundaryTest extends TestCase
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

    public function test_second_intent_for_same_order_cannot_bypass_attempt_claim_even_on_another_merchant(): void
    {
        $firstIntent = $this->intent;
        $first = $this->prepare($this->data($this->reference()));
        config()->set('payments.dispatch_enabled', true);
        $transport = $this->transport();
        $action = new DispatchPreparedPaymentAction($transport, app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class));
        $attempt = $action->execute($first->uuid, 'local.checkout');
        foreach ([false, true] as $differentMerchant) {
            if ($differentMerchant) {
                $this->merchant = MerchantAccount::factory()->create();
                Http::fake(fn () => Http::response('<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>456</gatewayId><currencies><currency>USD</currency></currencies></getMerchantDetailsResponse>'));
                $this->binding = app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '456', 'USD'));
            }
            $this->intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $firstIntent->customer_id, $this->merchant->id, GatewayProvider::AuthorizeNet, GatewayEnvironment::Sandbox, 2500, 'USD', 'local.checkout'));
            $second = $this->prepare($this->data($this->reference()));
            $this->invalid(fn () => $action->execute($second->uuid, 'local.checkout'));
        }
        $this->assertSame($attempt->id, $action->execute($first->uuid, 'local.checkout')->id);
        $this->assertDatabaseCount('payment_dispatch_attempts', 1);
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

    private function transport(?\Closure $callback = null): SyntheticDispatchTransport
    {
        return new SyntheticDispatchTransport($callback);
    }

    private function action(SyntheticDispatchTransport $transport): DispatchPreparedPaymentAction
    {
        return new DispatchPreparedPaymentAction($transport, app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class));
    }

    public function test_disabled_by_default_and_no_default_transport_or_outer_transaction(): void
    {
        $p = $this->prepare($this->data($this->reference()));
        $t = $this->transport();
        $this->assertFalse(app()->bound(PaymentDispatchTransport::class));
        $this->invalid(fn () => $this->action($t)->execute($p->uuid, 'local.checkout'));
        config(['payments.dispatch_enabled' => true]);
        DB::transaction(fn () => $this->invalid(fn () => $this->action($t)->execute($p->uuid, 'local.checkout')));
        $this->assertSame(0, $t->calls);
        $this->assertDatabaseCount('payment_dispatch_attempts', 0);
    }

    public function test_committed_claim_owns_exactly_one_transport_invocation_and_receipt(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $p = $this->prepare($this->data($this->reference()));
        $t = $this->transport(function (PaymentDispatchRequest $request) use ($p): PaymentDispatchReceipt {
            $this->assertSame(0, DB::transactionLevel());
            $row = PaymentDispatchAttempt::where('uuid', $request->attempt_uuid)->sole();
            $this->assertSame('claimed', $row->status);
            $this->assertNull($row->transport_started_at);
            $this->assertSame($p->prepared_scope['merchant_reference'], $request->merchant_reference);
            $this->assertSame($p->prepared_scope['merchant_binding_fingerprint'], $request->merchant_binding_fingerprint);
            $this->assertSame(2500, $request->amount_minor);

            return new PaymentDispatchReceipt($request->request_fingerprint, '1', '600001', $request->merchant_reference);
        });
        $action = $this->action($t);
        $row = $action->execute($p->uuid, 'local.checkout')->fresh();
        $this->assertSame('response_observed', $row->status);
        $this->assertSame('600001', $row->receipt['transaction_id']);
        $this->assertNotNull($row->transport_started_at);
        $this->assertGreaterThanOrEqual($row->claimed_at, $row->transport_started_at);
        $this->assertSame($row->id, $action->execute($p->uuid, 'local.checkout')->id);
        $this->assertSame(1, $t->calls);
        $this->assertSame(PaymentOperationState::Prepared, PaymentOperation::find($p->payment_operation_id)->state);
        $this->assertArrayNotHasKey('receipt', $row->toArray());
        $this->assertStringNotContainsString('600001', DB::table('payment_dispatch_attempts')->value('receipt'));
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_competing_reentrant_caller_sees_claim_and_never_invokes_transport(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $p = $this->prepare($this->data($this->reference()));
        $other = $this->transport();
        $t = $this->transport(function (PaymentDispatchRequest $request) use ($p, $other): PaymentDispatchReceipt {
            $row = $this->action($other)->execute($p->uuid, 'local.checkout');
            $this->assertSame('claimed', $row->status);
            $this->assertSame(0, $other->calls);

            return new PaymentDispatchReceipt($request->request_fingerprint, '1', '600001', $request->merchant_reference);
        });
        $this->action($t)->execute($p->uuid, 'local.checkout');
        $this->assertSame(1, $t->calls);
        $this->assertSame(0, $other->calls);
    }

    public function test_timeout_retains_unknown_without_redelivery_or_raw_error(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $p = $this->prepare($this->data($this->reference()));
        $t = $this->transport(fn () => throw new RuntimeException('synthetic-sensitive-card-or-key'));
        $row = $this->action($t)->execute($p->uuid, 'local.checkout');
        $this->assertSame('outcome_unknown', $row->status);
        $this->assertNull($row->receipt);
        $this->assertStringNotContainsString('synthetic-sensitive', json_encode($row->getAttributes()));
        $this->assertSame($row->id, $this->action($t)->execute($p->uuid, 'local.checkout')->id);
        $this->assertSame(1, $t->calls);
    }

    public function test_crash_after_claim_commit_before_call_remains_claimed_and_cannot_redispatch(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $p = $this->prepare($this->data($this->reference()));
        $t = $this->transport();
        PaymentDispatchAttempt::created(fn () => DB::afterCommit(fn () => throw new RuntimeException('synthetic crash after commit')));
        try {
            $this->action($t)->execute($p->uuid, 'local.checkout');
            $this->fail('Expected simulated crash.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic crash after commit', $e->getMessage());
        } finally {
            app('events')->forget('eloquent.created: '.PaymentDispatchAttempt::class);
        }
        $this->assertDatabaseCount('payment_dispatch_attempts', 1);
        $row = $this->action($t)->execute($p->uuid, 'local.checkout');
        $this->assertSame('claimed', $row->status);
        $this->assertNull($row->transport_started_at);
        $this->assertSame(0, $t->calls);
    }

    public function test_response_persistence_failure_retains_claim_and_cannot_repeat_transport(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $p = $this->prepare($this->data($this->reference()));
        $t = $this->transport();
        PaymentDispatchAttempt::updating(fn () => throw new RuntimeException('synthetic database loss'));
        try {
            $this->action($t)->execute($p->uuid, 'local.checkout');
            $this->fail('Expected persistence failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic database loss', $e->getMessage());
        } finally {
            // Restore the model's own immutable transition guard after the injected failure.
            app('events')->forget('eloquent.updating: '.PaymentDispatchAttempt::class);
            PaymentDispatchAttempt::clearBootedModels();
        }
        $row = $this->action($t)->execute($p->uuid, 'local.checkout');
        $this->assertSame('claimed', $row->status);
        $this->assertNull($row->receipt);
        $this->assertSame(1, $t->calls);
    }

    public function test_mismatched_receipt_is_unqualified_and_cannot_release_intent_interlock(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $one = $this->prepare($this->data($this->reference()));
        $two = $this->prepare($this->data($this->reference()));
        $t = $this->transport(fn (PaymentDispatchRequest $r) => new PaymentDispatchReceipt($r->request_fingerprint, '1', '600001', 'wrong-reference'));
        $row = $this->action($t)->execute($one->uuid, 'local.checkout');
        $this->assertSame('outcome_unknown', $row->status);
        $this->assertSame('wrong-reference', $row->receipt['echoed_ref_id']);
        $this->invalid(fn () => $this->action($t)->execute($two->uuid, 'local.checkout'));
        $this->assertSame(1, $t->calls);
    }

    public function test_even_observed_sibling_response_does_not_authorize_another_sale(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $one = $this->prepare($this->data($this->reference()));
        $two = $this->prepare($this->data($this->reference()));
        $t = $this->transport();
        $this->action($t)->execute($one->uuid, 'local.checkout');
        $this->invalid(fn () => $this->action($t)->execute($two->uuid, 'local.checkout'));
        $this->assertSame(1, $t->calls);
    }

    public function test_current_drift_and_wrong_executor_refuse_before_invocation(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $p = $this->prepare($this->data($this->reference()));
        $t = $this->transport();
        $this->invalid(fn () => $this->action($t)->execute($p->uuid, 'wrong.executor'));
        $this->merchant->update(['authnet_transaction_key' => 'synthetic-rotated']);
        $this->invalid(fn () => $this->action($t)->execute($p->uuid, 'local.checkout'));
        $this->assertSame(0, $t->calls);
        $this->assertDatabaseCount('payment_dispatch_attempts', 0);
    }

    public function test_parent_association_without_actual_dispatch_attempt_cannot_authorize_child_transport(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $auth = $this->reference(PaymentOperationPurpose::Authorize);
        $parent = $this->prepare($this->data($auth));
        $capture = $this->reference(PaymentOperationPurpose::Capture, PaymentOperation::find($auth->payment_operation_id));
        $child = $this->prepare($this->data($capture));
        $proof = new PaymentDispatchOriginal('600001', $parent->id, $parent->payment_operation_id,
            $parent->canonical_account_key, $parent->environment, str_repeat('a', 64));
        $resolver = new class($proof) implements PaymentDispatchLineageResolver
        {
            public function __construct(private readonly PaymentDispatchOriginal $proof) {}

            public function resolve(PaymentDispatchPreparation $preparation): PaymentDispatchOriginal
            {
                return $this->proof;
            }
        };
        $t = $this->transport();
        $action = new DispatchPreparedPaymentAction($t, app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class), $resolver);
        $this->invalid(fn () => $action->execute($child->uuid, 'local.checkout'));
        $this->assertSame(0, $t->calls);
        $this->assertDatabaseCount('payment_dispatch_attempts', 0);
    }

    public function test_linked_purpose_requires_trusted_original_resolver(): void
    {
        config(['payments.dispatch_enabled' => true]);
        $auth = $this->reference(PaymentOperationPurpose::Authorize);
        $capture = $this->reference(PaymentOperationPurpose::Capture, PaymentOperation::find($auth->payment_operation_id));
        $p = $this->prepare($this->data($capture));
        $t = $this->transport();
        $this->invalid(fn () => $this->action($t)->execute($p->uuid, 'local.checkout'));
        $this->assertSame(0, $t->calls);
        $this->assertDatabaseCount('payment_dispatch_attempts', 0);
    }
}

class SyntheticDispatchTransport implements PaymentDispatchTransport
{
    public int $calls = 0;

    public function __construct(private readonly ?\Closure $callback = null) {}

    public function key(): string
    {
        return 'synthetic.dispatch';
    }

    public function supports(string $purpose): bool
    {
        return true;
    }

    public function dispatch(PaymentDispatchRequest $request): PaymentDispatchReceipt
    {
        $this->calls++;

        return $this->callback === null
            ? new PaymentDispatchReceipt($request->request_fingerprint, '1', '600001', $request->merchant_reference, $request->original_transaction_id)
            : ($this->callback)($request);
    }
}
