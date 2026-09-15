<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Actions\Payments\ResolvePaymentUncertaintyAction;
use App\Data\Payments\PaymentOperationData;
use App\Data\Payments\PaymentUncertaintyData;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Enums\Payments\PaymentOperationState;
use App\Enums\Payments\PaymentUncertaintyReason;
use App\Models\Commerce\Order;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentFinancialReadRequest;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentUncertaintyResolution;
use App\Services\Payments\ResolvePaymentUncertaintyEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PaymentFinancialFixture;
use Tests\TestCase;

class PaymentUncertaintyResolutionTest extends TestCase
{
    use PaymentFinancialFixture;

    private function operationFor(PaymentDispatchPreparation $preparation): PaymentOperation
    {
        return PaymentOperation::findOrFail($preparation->payment_operation_id);
    }

    private function resolveUncertainty(PaymentDispatchPreparation $preparation)
    {
        return app(ResolvePaymentUncertaintyAction::class)->execute($this->operationFor($preparation)->uuid);
    }

    private function freshAfterUncertainty(PaymentDispatchPreparation $preparation): void
    {
        $this->reports();
        $this->assertTrue($this->financial($preparation)->qualified_reported_amounts);
        $this->resetHttp();
    }

    public function test_owned_post_uncertainty_reporting_resolves_durably_without_changing_history_or_releasing_execution(): void
    {
        $p = $this->rootOwned();
        $op = $this->operationFor($p);
        $this->uncertainty($op);
        $before = $op->fresh()->getAttributes();
        $attempt = PaymentDispatchAttempt::where('payment_operation_id', $op->id)->firstOrFail();
        $claim = $attempt->getAttributes();
        $this->freshAfterUncertainty($p);
        $result = $this->resolveUncertainty($p);
        $this->assertSame('resolved_reported', $result->status);
        $this->assertTrue($result->currently_qualified);
        $this->assertFalse($result->execution_released);
        $this->assertSame('settled', $result->classification);
        $this->assertSame($before, $op->fresh()->getAttributes());
        $this->assertSame($claim, $attempt->fresh()->getAttributes());
        $this->assertSame(PaymentOperationState::Uncertain, $op->fresh()->state);
        $this->assertSame($result->resolution_id, $this->resolveUncertainty($p)->resolution_id);
        $audit = PaymentUncertaintyResolution::firstOrFail();
        $this->assertSame($op->fresh()->uncertainty_fingerprint, $audit->uncertainty_fingerprint);
        $this->assertStringNotContainsString('reported_amounts', $audit->getRawOriginal('evidence'));
        $this->assertArrayNotHasKey('evidence', $audit->toArray());
        $this->invalid(fn () => app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), PaymentIntent::findOrFail($op->payment_intent_id)->uuid, PaymentOperationPurpose::Refund, 500, 'local.checkout', $op->uuid)));
        $this->assertDatabaseCount('payment_uncertainty_resolutions', 1);
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_stale_or_new_failed_read_revokes_current_resolution_without_erasing_audit(): void
    {
        $p = $this->rootOwned();
        $this->uncertainty($this->operationFor($p));
        $this->freshAfterUncertainty($p);
        $first = $this->resolveUncertainty($p);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(301));
        $stale = $this->resolveUncertainty($p);
        $this->assertSame('resolution_unqualified', $stale->status);
        $this->assertFalse($stale->currently_qualified);
        $this->assertSame($first->resolution_id, $stale->resolution_id);
        $this->freshAfterUncertainty($p);
        $fresh = $this->resolveUncertainty($p);
        $this->assertTrue($fresh->currently_qualified);
        $this->assertSame($first->resolution_id, $fresh->resolution_id);
        Http::fake(['*' => Http::response('', 503)]);
        $this->financial($p);
        $this->assertFalse(app(ResolvePaymentUncertaintyEvidence::class)->execute($this->operationFor($p)->uuid)->currently_qualified);
        $this->assertDatabaseCount('payment_uncertainty_resolutions', 1);
    }

    public function test_pre_uncertainty_and_same_second_alias_reads_cannot_resolve_uncertain_capture(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        [$capture,$attempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $this->lineage($capture, $attempt);
        $this->financial($parent);
        $this->uncertainty($this->operationFor($capture));
        $this->assertFalse($this->assessment($capture)->qualified_reported_amounts);
        $this->invalid(fn () => $this->resolveUncertainty($capture));
        // A fresh read in the same second remains conservatively outside the supported temporal contract.
        $this->assertTrue($this->financial($parent)->qualified_reported_amounts);
        $this->invalid(fn () => $this->resolveUncertainty($capture));
        $this->reports();
        $this->assertTrue($this->financial($parent)->qualified_reported_amounts);
        $this->resetHttp();
        $this->assertTrue($this->resolveUncertainty($capture)->currently_qualified);
        Http::assertNothingSent();
    }

    public function test_missing_owned_receipt_cannot_be_recovered_from_a_caller_reference(): void
    {
        $p = $this->prepare();
        $this->readFor($p);
        $this->record($p);
        $this->uncertainty($this->operationFor($p));
        $this->resetHttp();
        $this->invalid(fn () => $this->resolveUncertainty($p));
        $this->assertDatabaseCount('payment_uncertainty_resolutions', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_attempt_and_conflicting_uncertainty_reference_remain_blocked(): void
    {
        $p = $this->rootOwned();
        $op = $this->operationFor($p);
        app(RecordPaymentUncertaintyAction::class)->execute(new PaymentUncertaintyData($op->uuid, PaymentUncertaintyReason::TransportTimeout, CarbonImmutable::now(), gateway_transaction_reference: '99999'));
        $this->freshAfterUncertainty($p);
        $this->invalid(fn () => $this->resolveUncertainty($p));
        // A distinct order isolates unknown transport evidence from the mismatched reference above.
        $this->order = Order::factory()->create(['customer_id' => $this->order->customer_id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $unknown = $this->rootOwned();
        $this->uncertainty($this->operationFor($unknown));
        DB::table('payment_dispatch_attempts')->where('payment_operation_id', $unknown->payment_operation_id)->update(['status' => 'outcome_unknown']);
        $this->invalid(fn () => $this->resolveUncertainty($unknown));
        $this->assertDatabaseCount('payment_uncertainty_resolutions', 0);
    }

    public function test_storage_failure_and_outer_transaction_never_return_committed_resolution(): void
    {
        $p = $this->rootOwned();
        $this->uncertainty($this->operationFor($p));
        $this->freshAfterUncertainty($p);
        DB::transaction(fn () => $this->invalid(fn () => $this->resolveUncertainty($p)));
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER uncertainty_resolution_failure BEFORE INSERT ON payment_uncertainty_resolutions BEGIN SELECT RAISE(ABORT,'synthetic refusal'); END");
        } else {
            DB::unprepared("CREATE TRIGGER uncertainty_resolution_failure BEFORE INSERT ON payment_uncertainty_resolutions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic refusal'");
        }
        try {
            $this->resolveUncertainty($p);
            $this->fail('Expected durable store refusal.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER uncertainty_resolution_failure');
        }
        $this->assertDatabaseCount('payment_uncertainty_resolutions', 0);
        $this->assertSame('unresolved', app(ResolvePaymentUncertaintyEvidence::class)->execute($this->operationFor($p)->uuid)->status);
        Http::assertNothingSent();
    }

    public function test_immutable_audit_does_not_override_later_conflicts_or_credential_drift(): void
    {
        $p = $this->rootOwned();
        $this->uncertainty($this->operationFor($p));
        $this->freshAfterUncertainty($p);
        $this->resolveUncertainty($p);
        $audit = PaymentUncertaintyResolution::firstOrFail();
        foreach (['update', 'delete'] as $method) {
            try {
                $method === 'delete' ? $audit->delete() : $audit->update(['evidence_fingerprint' => str_repeat('a', 64)]);
                $this->fail('Expected immutable audit.');
            } catch (\LogicException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->merchant->update(['authnet_transaction_key' => 'synthetic-drift']);
        $this->assertSame('resolution_unqualified', $this->resolveUncertainty($p)->status);
        $this->assertDatabaseCount('payment_uncertainty_resolutions', 1);
    }

    public function test_saved_resolution_cannot_override_new_pending_currency_or_authoritative_identity_conflict(): void
    {
        $p = $this->rootOwned();
        $this->uncertainty($this->operationFor($p));
        $this->freshAfterUncertainty($p);
        $first = $this->resolveUncertainty($p);
        $pending = PaymentFinancialReadRequest::findOrFail($first->financial_request_id)->replicate();
        $pending->save();
        $this->assertSame('resolution_unqualified', $this->resolveUncertainty($p)->status);
        $this->freshAfterUncertainty($p);
        $this->assertTrue($this->resolveUncertainty($p)->currently_qualified);
        $attempt = PaymentDispatchAttempt::where('payment_operation_id', $p->payment_operation_id)->firstOrFail();
        $id = $attempt->receipt['transaction_id'];
        $this->entities[$id]['rail'] = 'bankAccount';
        $this->reports();
        $this->financial($p);
        $this->assertSame('resolution_unqualified', $this->resolveUncertainty($p)->status);
        $this->entities[$id]['rail'] = 'creditCard';
        $this->freshAfterUncertainty($p);
        $this->entities['900000'] = $this->entities[$id];
        $this->reports();
        $this->record($p, '900000');
        $blocked = $this->resolveUncertainty($p);
        $this->assertSame('resolution_unqualified', $blocked->status);
        $this->assertSame($first->resolution_id, $blocked->resolution_id);
        $this->assertDatabaseCount('payment_uncertainty_resolutions', 1);
    }
}
