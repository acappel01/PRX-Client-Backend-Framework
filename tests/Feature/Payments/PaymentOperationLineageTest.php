<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\DispatchPreparedPaymentAction;
use App\Actions\Payments\PreparePaymentDispatchAction;
use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordAuthorizeNetAssociationAction;
use App\Actions\Payments\RecordAuthorizeNetOperationLineageAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Actions\Payments\ReservePaymentOperationReferenceAction;
use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Contracts\Payments\PaymentDispatchTransport;
use App\Data\Payments\GatewayAccountBindingData;
use App\Data\Payments\PaymentDispatchPreparationData;
use App\Data\Payments\PaymentDispatchReceipt;
use App\Data\Payments\PaymentDispatchRequest;
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
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationEffectAssociation;
use App\Models\Payments\PaymentOperationReference;
use App\Models\Payments\PaymentTransactionAssociation;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\ResolvePaymentOperationLineage;
use App\Services\Payments\ResolvePaymentTransactionAssociation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentOperationLineageTest extends TestCase
{
    private MerchantAccount $merchant;

    private Order $order;

    private GatewayAccountBinding $binding;

    private string $processorXml = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.dispatch_enabled' => true]);
        Bus::fake();
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
        return '<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>123</gatewayId><currencies><currency>USD</currency></currencies>'.$this->processorXml.'</getMerchantDetailsResponse>';
    }

    private function operation(PaymentOperationPurpose $purpose = PaymentOperationPurpose::Sale): PaymentOperation
    {
        $intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $this->order->customer_id, $this->merchant->id, GatewayProvider::AuthorizeNet, $this->merchant->environment, 2500, 'USD', 'local.checkout'));

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

    private array $entities = [];

    private int $nextTransaction = 8000;

    private function dispatchOwned(PaymentDispatchPreparation $preparation): PaymentDispatchAttempt
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $id = (string) ++$this->nextTransaction;
        $transport = new class($id) implements PaymentDispatchTransport
        {
            public function __construct(private string $id) {}

            public function key(): string
            {
                return 'synthetic.lineage';
            }

            public function supports(string $purpose): bool
            {
                return true;
            }

            public function dispatch(PaymentDispatchRequest $request): PaymentDispatchReceipt
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Transport must be outside transaction.');
                }
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

                return new PaymentDispatchReceipt($request->request_fingerprint, '1',
                    in_array($request->purpose, ['capture', 'void'], true) ? $request->original_transaction_id : $this->id,
                    $request->merchant_reference, $request->original_transaction_id);
            }
        };
        $this->app->instance(PaymentDispatchTransport::class, $transport);

        return app(DispatchPreparedPaymentAction::class)->execute($preparation->uuid, 'local.checkout');
    }

    private function reports(?callable $during = null): void
    {
        $this->resetHttp();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        Http::fake(function ($request) use ($during) {
            $this->assertSame(0, DB::transactionLevel());
            if (str_contains($request->body(), 'getMerchantDetailsRequest')) {
                return Http::response($this->merchantXml());
            }
            preg_match('/<transId>([0-9]+)<\/transId>/', $request->body(), $match);
            $entity = $this->entities[$match[1]];
            if ($during) {
                $during();
            }
            $original = $entity['original'] === null ? '' : '<refTransId>'.$entity['original'].'</refTransId>';

            return Http::response('<getTransactionDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><transaction><transId>'.$match[1].'</transId><submitTimeUTC>'.$entity['submitted'].'</submitTimeUTC><payment><'.$entity['rail'].'/></payment><transactionType>'.$entity['type'].'</transactionType><transactionStatus>'.$entity['status'].'</transactionStatus><authAmount>'.number_format($entity['auth'] / 100, 2, '.', '').'</authAmount><settleAmount>'.number_format($entity['settle'] / 100, 2, '.', '').'</settleAmount>'.$original.'</transaction><transrefId>'.$entity['reference'].'</transrefId></getTransactionDetailsResponse>');
        });
    }

    private function rootOwned(PaymentOperationPurpose $purpose = PaymentOperationPurpose::Sale): PaymentDispatchPreparation
    {
        $preparation = $this->prepare($this->operation($purpose));
        $attempt = $this->dispatchOwned($preparation);
        $id = $attempt->receipt['transaction_id'];
        $this->entities[$id] = ['type' => $purpose === PaymentOperationPurpose::Sale ? 'authCaptureTransaction' : 'authOnlyTransaction',
            'status' => $purpose === PaymentOperationPurpose::Sale ? 'settledSuccessfully' : 'authorizedPendingCapture',
            'submitted' => CarbonImmutable::now()->subSecond()->format('Y-m-d\TH:i:s.u\Z'), 'auth' => 2500, 'settle' => 2500,
            'reference' => $preparation->prepared_scope['merchant_reference'], 'original' => null, 'rail' => 'creditCard'];
        $this->reports();
        $this->assertSame('associated_only', $this->record($preparation, $id)->status);

        return $preparation;
    }

    private function childPreparation(PaymentDispatchPreparation $parent, PaymentOperationPurpose $purpose, int $amount = 2500): PaymentDispatchPreparation
    {
        $parentOperation = PaymentOperation::findOrFail($parent->payment_operation_id);
        $operation = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(),
            PaymentIntent::findOrFail($parentOperation->payment_intent_id)->uuid, $purpose, $amount, 'local.checkout', $parentOperation->uuid));

        return $this->prepare($operation);
    }

    private function childOwned(PaymentDispatchPreparation $parent, PaymentOperationPurpose $purpose, int $amount = 2500): array
    {
        $preparation = $this->childPreparation($parent, $purpose, $amount);
        $attempt = $this->dispatchOwned($preparation);
        $id = $attempt->receipt['transaction_id'];
        $original = $attempt->request_facts['original_transaction_id'];
        if ($purpose === PaymentOperationPurpose::Refund) {
            $this->entities[$original]['status'] = 'settledSuccessfully';
            $this->entities[$id] = ['type' => 'refundTransaction', 'status' => 'refundPendingSettlement',
                'submitted' => CarbonImmutable::now()->subSecond()->format('Y-m-d\TH:i:s.u\Z'), 'auth' => $amount, 'settle' => $amount,
                'reference' => $preparation->prepared_scope['merchant_reference'], 'original' => $original, 'rail' => 'creditCard'];
        } else {
            $this->entities[$id]['status'] = $purpose === PaymentOperationPurpose::Capture ? 'capturedPendingSettlement' : 'voided';
            $this->entities[$id]['settle'] = $purpose === PaymentOperationPurpose::Capture ? $amount : 0;
        }
        $this->reports();

        return [$preparation, $attempt];
    }

    private function lineage(PaymentDispatchPreparation $preparation, PaymentDispatchAttempt $attempt)
    {
        return app(RecordAuthorizeNetOperationLineageAction::class)->execute($preparation->id, $attempt->id);
    }

    public function test_capture_then_void_share_original_entity_without_false_operation_conflicts(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        [$capture, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $result = $this->lineage($capture, $attempt);
        $this->assertSame('effect_correlated_only', $result->status);
        $this->assertTrue($result->actor_correlated);
        $this->assertFalse($result->operation_verified);
        $this->assertFalse($result->financial_effects_verified);
        [$void, $voidAttempt] = $this->childOwned($capture, PaymentOperationPurpose::Void, 2000);
        $this->assertSame('effect_correlated_only', $this->lineage($void, $voidAttempt)->status);
        $this->assertSame($attempt->receipt['transaction_id'], $voidAttempt->receipt['transaction_id']);
        $this->assertSame('effect_correlated_only', app(ResolvePaymentOperationLineage::class)->execute($capture->id)->status);
        $this->assertSame('associated_only', app(ResolvePaymentTransactionAssociation::class)->execute($parent->id)->status);
        $this->assertDatabaseCount('payment_transaction_associations', 1);
        $this->assertDatabaseCount('payment_operation_effect_associations', 2);
        $this->assertDatabaseCount('payment_outcome_observations', 0);
        Bus::assertNothingDispatched();
    }

    public function test_refund_from_capture_uses_distinct_entity_and_authoritative_original_settlement(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        [$capture, $captureAttempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $this->lineage($capture, $captureAttempt);
        $this->entities[$captureAttempt->receipt['transaction_id']]['status'] = 'settledSuccessfully';
        $this->reports();
        $this->lineage($capture, $captureAttempt);
        [$refund, $attempt] = $this->childOwned($capture, PaymentOperationPurpose::Refund, 500);
        $result = $this->lineage($refund, $attempt);
        $this->assertSame('effect_correlated_only', $result->status);
        $this->assertNotSame($captureAttempt->receipt['transaction_id'], $attempt->receipt['transaction_id']);
        $row = PaymentOperationEffectAssociation::findOrFail($result->effect_association_ids[0]);
        $this->assertSame($captureAttempt->receipt['transaction_id'], $row->facts['entity']['original_transaction_id']);
        $this->assertStringNotContainsString('transaction_id', $row->getRawOriginal('facts'));
        $this->assertArrayNotHasKey('facts', $row->toArray());
        $this->assertDatabaseCount('canonical_events', 0);
    }

    public function test_reporting_only_parent_is_not_owned_dispatch_evidence(): void
    {
        $parent = $this->prepare();
        $this->readFor($parent);
        $this->record($parent);
        $child = $this->childPreparation($parent, PaymentOperationPurpose::Refund, 500);
        $this->invalid(fn () => $this->dispatchOwned($child));
        $this->assertDatabaseCount('payment_dispatch_attempts', 0);
        $this->assertDatabaseCount('payment_operation_effect_associations', 0);
    }

    public function test_current_status_alone_wrong_receipt_lineage_and_scope_drift_refuse(): void
    {
        $parent = $this->rootOwned();
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $parentAttempt = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $parent->id)->firstOrFail();
        $this->invalid(fn () => $this->lineage($refund, $parentAttempt));
        $this->entities[$attempt->receipt['transaction_id']]['original'] = '9999';
        $this->invalid(fn () => $this->lineage($refund, $attempt));
        $this->entities[$attempt->receipt['transaction_id']]['original'] = $attempt->request_facts['original_transaction_id'];
        $this->reports(fn () => $this->merchant->update(['authnet_transaction_key' => 'synthetic-changed']));
        $this->invalid(fn () => $this->lineage($refund, $attempt));
        $this->assertDatabaseCount('payment_operation_effect_associations', 0);
    }

    public function test_parent_conflicts_propagate_to_existing_descendant_and_block_new_reads(): void
    {
        $parent = $this->rootOwned();
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $this->lineage($refund, $attempt);
        $conflict = PaymentTransactionAssociation::firstOrFail()->replicate();
        $conflict->transaction_key = hash('sha256', 'synthetic-other-entity');
        $conflict->evidence_fingerprint = hash('sha256', 'synthetic-conflicting-read');
        $conflict->save();
        $this->assertSame('parent_conflict_quarantined', app(ResolvePaymentOperationLineage::class)->execute($refund->id)->status);
        $this->invalid(fn () => $this->lineage($refund, $attempt));
    }

    public function test_currency_unqualified_effect_is_retained_and_no_outer_transaction_or_partial_void(): void
    {
        $parent = $this->rootOwned();
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        DB::transaction(fn () => $this->invalid(fn () => $this->lineage($refund, $attempt)));
        $this->entities[$attempt->receipt['transaction_id']]['rail'] = 'bankAccount';
        $this->assertSame('currency_unqualified', $this->lineage($refund, $attempt)->status);
        $this->assertDatabaseCount('payment_operation_effect_associations', 1);
        // Independent partial-void scenario needs its own commercial obligation.
        $this->order = Order::factory()->create(['customer_id' => $this->order->customer_id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $other = $this->rootOwned();
        $void = $this->childPreparation($other, PaymentOperationPurpose::Void, 500);
        $this->invalid(fn () => $this->dispatchOwned($void));
    }

    public function test_refund_after_owned_response_or_unsettled_parent_refuses_and_storage_is_durable(): void
    {
        $parent = $this->rootOwned();
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $id = $attempt->receipt['transaction_id'];
        $original = $attempt->request_facts['original_transaction_id'];
        $submitted = $this->entities[$id]['submitted'];
        $this->entities[$id]['submitted'] = $attempt->completed_at->addMicrosecond()->format('Y-m-d\TH:i:s.u\Z');
        $this->invalid(fn () => $this->lineage($refund, $attempt));
        $this->entities[$id]['submitted'] = $submitted;
        $this->entities[$original]['status'] = 'capturedPendingSettlement';
        $this->invalid(fn () => $this->lineage($refund, $attempt));
        $this->entities[$original]['status'] = 'settledSuccessfully';
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER effect_storage_failure BEFORE INSERT ON payment_operation_effect_associations BEGIN SELECT RAISE(ABORT, 'synthetic refusal'); END");
        } else {
            DB::unprepared("CREATE TRIGGER effect_storage_failure BEFORE INSERT ON payment_operation_effect_associations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic refusal'");
        }
        try {
            $this->lineage($refund, $attempt);
            $this->fail('Expected storage refusal.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER effect_storage_failure');
        }
        $this->assertDatabaseCount('payment_operation_effect_associations', 0);
    }

    public function test_root_and_refund_entity_conflict_is_symmetric_in_both_insertion_orders(): void
    {
        $parent = $this->rootOwned();
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $this->lineage($refund, $attempt);
        $refundRow = PaymentOperationEffectAssociation::firstOrFail();
        $root = PaymentTransactionAssociation::firstOrFail()->replicate();
        $otherPreparation = $this->prepare();
        // Synthetic conflicting persisted evidence exercises both assessment namespaces.
        $root->payment_dispatch_preparation_id = $otherPreparation->id;
        $root->payment_operation_id = $otherPreparation->payment_operation_id;
        $root->transaction_key = $refundRow->transaction_key;
        $root->evidence_fingerprint = hash('sha256', 'synthetic-root-refund-conflict');
        $root->save();
        $this->assertSame('conflict_quarantined', app(ResolvePaymentTransactionAssociation::class)->execute($otherPreparation->id)->status);
        $this->assertSame('conflict_quarantined', app(ResolvePaymentOperationLineage::class)->execute($refund->id)->status);
        // Reverse insertion order using another account-scoped identity and immutable append-only rows.
        $otherRoot = $this->prepare();
        $root2 = $root->replicate();
        $root2->payment_dispatch_preparation_id = $otherRoot->id;
        $root2->payment_operation_id = $otherRoot->payment_operation_id;
        $root2->transaction_key = hash('sha256', 'reverse-entity');
        $root2->evidence_fingerprint = hash('sha256', 'reverse-root');
        $root2->save();
        $copy = $refundRow->replicate();
        $copy->transaction_key = $root2->transaction_key;
        $copy->evidence_fingerprint = hash('sha256', 'reverse-refund');
        $copy->save();
        $this->assertSame('conflict_quarantined', app(ResolvePaymentTransactionAssociation::class)->execute($otherRoot->id)->status);
        $this->assertSame('conflict_quarantined', app(ResolvePaymentOperationLineage::class)->execute($refund->id)->status);
    }

    public function test_ambiguous_refund_entity_claims_and_excess_totals_quarantine_all_siblings(): void
    {
        $parent = $this->rootOwned();
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $this->lineage($refund, $attempt);
        $one = PaymentOperationEffectAssociation::firstOrFail();
        $sibling = $this->childPreparation($parent, PaymentOperationPurpose::Refund, 2000);
        $two = $one->replicate();
        $two->payment_dispatch_preparation_id = $sibling->id;
        $two->payment_operation_id = $sibling->payment_operation_id;
        $two->amount_minor = 2000;
        $two->transaction_key = hash('sha256', 'synthetic-sibling-refund');
        $two->evidence_fingerprint = hash('sha256', 'synthetic-sibling-evidence');
        $two->save();
        $this->assertSame('effect_correlated_only', app(ResolvePaymentOperationLineage::class)->execute($sibling->id)->status);
        $thirdPreparation = $this->childPreparation($parent, PaymentOperationPurpose::Refund, 500);
        $third = $one->replicate();
        $third->payment_dispatch_preparation_id = $thirdPreparation->id;
        $third->payment_operation_id = $thirdPreparation->payment_operation_id;
        $third->transaction_key = hash('sha256', 'synthetic-over-bound-entity');
        $third->evidence_fingerprint = hash('sha256', 'synthetic-over-bound-evidence');
        $third->save();
        $this->assertSame('refund_bound_quarantined', app(ResolvePaymentOperationLineage::class)->execute($sibling->id)->status);
        $this->assertSame('refund_bound_quarantined', app(ResolvePaymentOperationLineage::class)->execute($refund->id)->status);
        $conflict = $one->replicate();
        $conflict->transaction_key = hash('sha256', 'synthetic-competing-refund');
        $conflict->evidence_fingerprint = hash('sha256', 'synthetic-competing-evidence');
        $conflict->save();
        $this->assertSame('conflict_quarantined', app(ResolvePaymentOperationLineage::class)->execute($sibling->id)->status);
        $this->assertSame('conflict_quarantined', app(ResolvePaymentOperationLineage::class)->execute($refund->id)->status);
        $this->assertDatabaseCount('payment_operation_effect_associations', 4);
    }

    public function test_capture_and_refund_exact_amount_checks_and_parent_status_before_dispatch(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        [$capture, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $id = $attempt->receipt['transaction_id'];
        $this->entities[$id]['auth'] = 2600;
        $this->invalid(fn () => $this->lineage($capture, $attempt));
        $this->entities[$id]['auth'] = 2500;
        $this->entities[$id]['settle'] = 1900;
        $this->invalid(fn () => $this->lineage($capture, $attempt));
        $this->entities[$id]['settle'] = 2000;
        $this->lineage($capture, $attempt);
        $refund = $this->childPreparation($capture, PaymentOperationPurpose::Refund, 500);
        $this->invalid(fn () => $this->dispatchOwned($refund));
        $this->entities[$id]['status'] = 'settledSuccessfully';
        $this->reports();
        $this->lineage($capture, $attempt);
        $refundAttempt = $this->dispatchOwned($refund);
        $refundId = $refundAttempt->receipt['transaction_id'];
        $this->entities[$refundId] = ['type' => 'refundTransaction', 'status' => 'refundPendingSettlement',
            'submitted' => CarbonImmutable::now()->subSecond()->format('Y-m-d\TH:i:s.u\Z'), 'auth' => 500, 'settle' => 501,
            'reference' => $refund->prepared_scope['merchant_reference'], 'original' => $id, 'rail' => 'creditCard'];
        $this->reports();
        $this->invalid(fn () => $this->lineage($refund, $refundAttempt));
        $this->assertDatabaseCount('payment_operation_effect_associations', 2);
    }

    public function test_expired_parent_and_cross_intent_or_account_preparation_refuse_before_dispatch(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        $row = PaymentTransactionAssociation::firstOrFail();
        $copy = $row->replicate();
        $facts = $copy->facts;
        $facts['transaction_status'] = 'expired';
        $copy->facts = $facts;
        $copy->evidence_fingerprint = hash('sha256', 'synthetic-current-expired');
        $copy->save();
        $child = $this->childPreparation($parent, PaymentOperationPurpose::Capture);
        $this->invalid(fn () => $this->dispatchOwned($child));
        $this->assertDatabaseCount('payment_dispatch_attempts', 1);
        $other = $this->operation();
        $this->invalid(fn () => app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(),
            PaymentIntent::findOrFail($other->payment_intent_id)->uuid, PaymentOperationPurpose::Capture, 2500, 'local.checkout',
            PaymentOperation::findOrFail($parent->payment_operation_id)->uuid)));
        // Synthetic persisted corruption models a wrong canonical-account parent evidence row.
        DB::table('payment_dispatch_preparations')->where('id', $parent->id)->update(['canonical_account_key' => str_repeat('f', 64)]);
        $this->invalid(fn () => $this->dispatchOwned($child));
    }
}
