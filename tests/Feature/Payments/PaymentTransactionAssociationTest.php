<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreparePaymentDispatchAction;
use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordAuthorizeNetAssociationAction;
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
use App\Enums\Payments\PaymentUncertaintyReason;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Models\Payments\PaymentTransactionAssociation;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\ResolvePaymentTransactionAssociation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentTransactionAssociationTest extends TestCase
{
    private MerchantAccount $merchant;

    private Order $order;

    private GatewayAccountBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15T12:00:00.123456Z'));
        $this->beforeApplicationDestroyed(fn () => CarbonImmutable::setTestNow());
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        $this->mock(PaymentGatewayManager::class, function ($mock): void {
            $mock->shouldNotReceive('forAccount', 'forAccountId', 'driver', 'default');
        });
        $customer = Customer::factory()->create();
        $this->order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $this->merchant = MerchantAccount::factory()->create();
        Http::fake(['*' => Http::response($this->merchantXml())]);
        $this->binding = app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '123', 'USD', $this->merchant->provider_merchant_profile_id));
        $this->resetHttp();
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function merchantXml(): string
    {
        return '<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>123</gatewayId><currencies><currency>USD</currency></currencies></getMerchantDetailsResponse>';
    }

    private function operation(PaymentOperationPurpose $purpose = PaymentOperationPurpose::Sale): PaymentOperation
    {
        $intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $this->order->customer_id, $this->merchant->id, GatewayProvider::AuthorizeNet, GatewayEnvironment::Sandbox, 2500, 'USD', 'local.checkout'));

        return app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $intent->uuid, $purpose, 2500, 'local.checkout'));
    }

    private function reserve(PaymentOperation $operation): PaymentOperationReference
    {
        return app(ReservePaymentOperationReferenceAction::class)->execute($operation->uuid, $this->binding->id);
    }

    private function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected refusal.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function uncertainty(PaymentOperation $operation): void
    {
        app(RecordPaymentUncertaintyAction::class)->execute(new PaymentUncertaintyData($operation->uuid, PaymentUncertaintyReason::TransportTimeout, CarbonImmutable::now()));
    }

    private function fakeRead(string $reference, ?callable $during = null, string $type = 'authCaptureTransaction', string $transactionId = '6001', string $submitted = '2026-09-15T12:00:01.123456Z', string $rail = 'creditCard'): void
    {
        Http::fake(function ($request) use ($reference, $during, $type, $transactionId, $submitted, $rail) {
            if (str_contains($request->body(), 'getMerchantDetailsRequest')) {
                return Http::response($this->merchantXml());
            }
            if ($during) {
                $during();
            }
            $status = $type === 'authOnlyTransaction' ? 'authorizedPendingCapture' : 'settledSuccessfully';

            return Http::response('<getTransactionDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><transaction><transId>'.$transactionId.'</transId><submitTimeUTC>'.$submitted.'</submitTimeUTC><payment><'.$rail.'/></payment><transactionType>'.$type.'</transactionType><transactionStatus>'.$status.'</transactionStatus><authAmount>25.00</authAmount><settleAmount>25.00</settleAmount></transaction><transrefId>'.$reference.'</transrefId></getTransactionDetailsResponse>');
        });
    }

    private function prepare(?PaymentOperation $operation = null): PaymentDispatchPreparation
    {
        $reference = $this->reserve($operation ?? $this->operation());

        return app(PreparePaymentDispatchAction::class)->execute(new PaymentDispatchPreparationData((string) Str::uuid(), $reference->id, 'local.checkout'));
    }

    private function record($preparation, string $transactionId = '6001')
    {
        return app(RecordAuthorizeNetAssociationAction::class)->execute($preparation->id, $transactionId);
    }

    private function readFor($preparation, string $transactionId = '6001', ?callable $during = null): void
    {
        $this->resetHttp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15T12:01:00.123456Z'));
        $this->fakeRead($preparation->prepared_scope['merchant_reference'], $during, transactionId: $transactionId);
    }

    public function test_authenticated_sale_and_authorization_are_association_only_and_encrypted(): void
    {
        foreach ([PaymentOperationPurpose::Sale, PaymentOperationPurpose::Authorize] as $purpose) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15T12:00:00.123456Z'));
            $preparation = $this->prepare($this->operation($purpose));
            $before = PaymentOperation::find($preparation->payment_operation_id)->getAttributes();
            $this->readFor($preparation, $purpose === PaymentOperationPurpose::Sale ? '6001' : '6002');
            if ($purpose === PaymentOperationPurpose::Authorize) {
                $this->resetHttp();
                $this->fakeRead($preparation->prepared_scope['merchant_reference'], type: 'authOnlyTransaction', transactionId: '6002');
            }
            $result = $this->record($preparation, $purpose === PaymentOperationPurpose::Sale ? '6001' : '6002');
            $this->assertSame('associated_only', $result->status);
            $this->assertFalse($result->dispatch_verified);
            $this->assertFalse($result->operation_verified);
            $this->assertFalse($result->financial_effects_verified);
            $this->assertSame($before, PaymentOperation::find($preparation->payment_operation_id)->getAttributes());
            $row = PaymentTransactionAssociation::findOrFail($result->association_ids[0]);
            $this->assertSame('preparation_only', $row->facts['provenance']);
            $this->assertSame('2026-09-15T12:00:00.123456Z', $row->facts['prepared_at']);
            $this->assertStringNotContainsString('merchant_reference', $row->getRawOriginal('facts'));
            $this->assertArrayNotHasKey('facts', $row->toArray());
        }
        $this->assertDatabaseCount('canonical_events', 0);
    }

    public function test_multiple_candidates_quarantine_all_without_first_winner(): void
    {
        $one = $this->prepare();
        $two = $this->prepare();
        $this->readFor($one);
        $this->assertSame('associated_only', $this->record($one)->status);
        $this->readFor($two);
        $this->assertSame('conflict_quarantined', $this->record($two)->status);
        $this->assertSame('conflict_quarantined', app(ResolvePaymentTransactionAssociation::class)->execute($one->id)->status);
        $this->readFor($one, '6002');
        $result = $this->record($one, '6002');
        $this->assertSame('conflict_quarantined', $result->status);
        $this->assertCount(3, $result->association_ids);
        $this->assertDatabaseCount('payment_transaction_associations', 3);
    }

    public function test_unqualified_currency_is_retained_and_blocks_resolution(): void
    {
        $preparation = $this->prepare();
        $this->readFor($preparation);
        $this->resetHttp();
        $this->fakeRead($preparation->prepared_scope['merchant_reference'], rail: 'bankAccount');
        $result = $this->record($preparation);
        $this->assertSame('currency_unqualified', $result->status);
        $this->assertDatabaseCount('payment_transaction_associations', 1);
    }

    public function test_preparation_precision_old_or_missing_submission_and_scope_drift_refuse(): void
    {
        $preparation = $this->prepare();
        foreach (['2026-09-15T12:00:00.123455Z', '2026-09-15T12:00:00.123456Z', '2026-09-15T12:02:00Z', ''] as $submitted) {
            $this->readFor($preparation);
            $this->resetHttp();
            $this->fakeRead($preparation->prepared_scope['merchant_reference'], submitted: $submitted);
            $this->invalid(fn () => $this->record($preparation));
        }
        $this->readFor($preparation, during: fn () => $this->order->update(['total_amount' => '26.00']));
        $this->invalid(fn () => $this->record($preparation));
        $this->assertDatabaseCount('payment_transaction_associations', 0);
    }

    public function test_outer_transaction_refuses_before_http_or_evidence(): void
    {
        $preparation = $this->prepare();
        DB::transaction(fn () => $this->invalid(fn () => $this->record($preparation)));
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_transaction_associations', 0);
    }

    public function test_descendant_purposes_explicitly_refuse_without_inferred_actor_or_lineage(): void
    {
        foreach ([PaymentOperationPurpose::Refund, PaymentOperationPurpose::Capture, PaymentOperationPurpose::Void] as $purpose) {
            $parent = $this->operation($purpose === PaymentOperationPurpose::Capture ? PaymentOperationPurpose::Authorize : PaymentOperationPurpose::Sale);
            $operation = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), PaymentIntent::findOrFail($parent->payment_intent_id)->uuid, $purpose, 2500, 'local.checkout', $parent->uuid));
            $preparation = $this->prepare($operation);
            $this->invalid(fn () => $this->record($preparation));
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_transaction_associations', 0);
    }

    public function test_same_second_later_submission_replay_and_evidence_immutability(): void
    {
        $preparation = $this->prepare();
        $this->readFor($preparation);
        $this->resetHttp();
        $this->fakeRead($preparation->prepared_scope['merchant_reference'], submitted: '2026-09-15T12:00:00.123457Z');
        $first = $this->record($preparation);
        $second = $this->record($preparation);
        $this->assertSame('associated_only', $first->status);
        $this->assertSame($first->association_ids, $second->association_ids);
        $this->assertDatabaseCount('payment_transaction_associations', 1);
        $row = PaymentTransactionAssociation::firstOrFail();
        foreach (['update', 'delete'] as $action) {
            try {
                $action === 'update' ? $row->update(['currency_qualified' => false]) : $row->delete();
                $this->fail('Expected immutable evidence.');
            } catch (\LogicException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_duplicate_local_binding_rows_share_conflict_namespace(): void
    {
        $one = $this->prepare();
        $this->readFor($one);
        $this->record($one);
        $this->merchant = MerchantAccount::factory()->create();
        $this->resetHttp();
        Http::fake(['*' => Http::response($this->merchantXml())]);
        $this->binding = app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '123', 'USD', $this->merchant->provider_merchant_profile_id));
        $this->resetHttp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15T12:00:00.123456Z'));
        $two = $this->prepare();
        $this->readFor($two);
        $this->assertSame('conflict_quarantined', $this->record($two)->status);
        $this->assertDatabaseCount('payment_association_scopes', 1);
        $this->assertSame('conflict_quarantined', app(ResolvePaymentTransactionAssociation::class)->execute($one->id)->status);
    }

    public function test_storage_failure_cannot_return_association_or_leave_partial_evidence(): void
    {
        $preparation = $this->prepare();
        $this->readFor($preparation);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER association_storage_failure BEFORE INSERT ON payment_transaction_associations BEGIN SELECT RAISE(ABORT, 'synthetic storage refusal'); END");
        } else {
            DB::unprepared("CREATE TRIGGER association_storage_failure BEFORE INSERT ON payment_transaction_associations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic storage refusal'");
        }
        try {
            $this->record($preparation);
            $this->fail('Storage refusal must propagate.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER association_storage_failure');
        }
        $this->assertDatabaseCount('payment_transaction_associations', 0);
    }

    public function test_graph_limit_is_explicitly_quarantined_and_credential_or_state_drift_refuses(): void
    {
        $preparation = $this->prepare();
        $this->readFor($preparation);
        $this->record($preparation);
        $row = PaymentTransactionAssociation::firstOrFail();
        for ($i = 0; $i < 256; $i++) {
            $copy = $row->replicate();
            $copy->evidence_fingerprint = hash('sha256', 'synthetic-'.$i);
            $copy->save();
        }
        $result = app(ResolvePaymentTransactionAssociation::class)->execute($preparation->id);
        $this->assertSame('evidence_limit_quarantined', $result->status);
        $this->assertCount(256, $result->association_ids);
        $this->readFor($preparation, during: fn () => $this->uncertainty(PaymentOperation::findOrFail($preparation->payment_operation_id)));
        $this->invalid(fn () => $this->record($preparation));
        $this->readFor($preparation, during: fn () => $this->merchant->update(['authnet_transaction_key' => 'synthetic-rotated']));
        $this->invalid(fn () => $this->record($preparation));
        $this->assertDatabaseCount('payment_transaction_associations', 257);
    }
}
