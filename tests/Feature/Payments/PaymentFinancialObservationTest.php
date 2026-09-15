<?php

namespace Tests\Feature\Payments;

use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentFinancialObservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentFinancialFixture;
use Tests\TestCase;

class PaymentFinancialObservationTest extends TestCase
{
    use PaymentFinancialFixture;

    public function test_sale_and_distinct_refund_report_meaningful_amounts_without_order_or_uncertainty_changes(): void
    {
        $parent = $this->rootOwned();
        $orderBefore = $this->order->fresh()->getAttributes();
        $rootResult = $this->financial($parent);
        $this->assertTrue($rootResult->qualified_reported_amounts);
        $this->assertSame(2500, $rootResult->amounts['settled_minor']);
        $this->assertFalse($rootResult->financial_effects_verified);
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $this->assertFalse($this->assessment($parent)->qualified_reported_amounts);
        $this->lineage($refund, $attempt);
        $pending = $this->financial($refund);
        $this->assertSame(500, $pending->amounts['refund_pending_minor']);
        $this->assertNotSame($rootResult->entity_key, $pending->entity_key);
        $this->entities[$attempt->receipt['transaction_id']]['status'] = 'refundSettledSuccessfully';
        $this->reports();
        $settled = $this->financial($refund);
        $this->assertSame(500, $settled->amounts['refunded_minor']);
        $this->assertSame(2500, $this->financial($parent)->amounts['settled_minor']);
        $this->assertSame($orderBefore, $this->order->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_outcome_observations', 0);
        $this->assertDatabaseCount('canonical_events', 0);
        Bus::assertNothingDispatched();
    }

    public function test_authorize_capture_void_share_one_latest_entity_without_double_counting(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        $authorized = $this->financial($parent);
        $this->assertSame(2500, $authorized->amounts['authorized_minor']);
        [$capture, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $this->assertSame('scope_conflict', $this->assessment($parent)->status);
        $this->lineage($capture, $attempt);
        $captured = $this->financial($capture);
        $this->assertSame(2000, $captured->amounts['captured_pending_minor']);
        $this->assertSame($authorized->entity_key, $captured->entity_key);
        $this->assertSame($captured->request_id, $this->assessment($parent)->request_id);
        [$void, $voidAttempt] = $this->childOwned($capture, PaymentOperationPurpose::Void, 2000);
        $this->lineage($void, $voidAttempt);
        $voided = $this->financial($void);
        $this->assertSame('voided', $voided->classification);
        $this->assertSame(2000, $voided->amounts['voided_minor']);
        $this->assertSame(0, $voided->amounts['settled_minor']);
        $this->assertSame($voided->observation_id, $this->assessment($parent)->observation_id);
        $this->assertSame($voided->observation_id, $this->assessment($capture)->observation_id);
    }

    public function test_settlement_of_authorization_requires_owned_capture_and_exact_amount(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        $attempt = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $parent->id)->firstOrFail();
        $this->entities[$attempt->receipt['transaction_id']]['status'] = 'settledSuccessfully';
        $this->reports();
        $this->assertSame('amount_or_actor_unqualified', $this->financial($parent)->status);
        $this->entities[$attempt->receipt['transaction_id']]['status'] = 'authorizedPendingCapture';
        [$capture, $captureAttempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $this->lineage($capture, $captureAttempt);
        $this->entities[$captureAttempt->receipt['transaction_id']]['settle'] = 2001;
        $this->assertSame('amount_or_actor_unqualified', $this->financial($capture)->status);
        $this->entities[$captureAttempt->receipt['transaction_id']]['settle'] = 2000;
        $this->entities[$captureAttempt->receipt['transaction_id']]['status'] = 'settledSuccessfully';
        $this->assertSame(2000, $this->financial($capture)->amounts['settled_minor']);
    }

    public function test_failed_unknown_reversal_and_stale_reads_never_fall_back_to_qualified_settlement(): void
    {
        $parent = $this->rootOwned();
        $this->assertTrue($this->financial($parent)->qualified_reported_amounts);
        $id = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $parent->id)->firstOrFail()->receipt['transaction_id'];
        foreach (['newProviderStatus' => 'unsupported_status', 'chargeback' => 'reversal_unqualified'] as $status => $expected) {
            $this->entities[$id]['status'] = $status;
            $this->assertSame($expected, $this->financial($parent)->status);
            $this->assertFalse($this->assessment($parent)->qualified_reported_amounts);
        }
        $this->entities[$id]['status'] = 'settledSuccessfully';
        $this->assertSame('reversal_unqualified', $this->financial($parent)->status);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(301));
        $this->assertSame('stale', $this->assessment($parent)->status);
        $this->resetHttp();
        Http::fake(['*' => Http::response('', 503)]);
        $this->assertSame('read_failed', $this->financial($parent)->status);
        $this->assertSame('read_failed', $this->assessment($parent)->status);
    }

    public function test_settlement_to_void_is_a_contradiction_not_a_negative_balance(): void
    {
        $parent = $this->rootOwned();
        $this->financial($parent);
        $id = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $parent->id)->firstOrFail()->receipt['transaction_id'];
        $this->entities[$id]['status'] = 'voided';
        $this->entities[$id]['auth'] = 0;
        $this->entities[$id]['settle'] = 0;
        $result = $this->financial($parent);
        $this->assertSame('transition_conflict', $result->status);
        $this->assertSame([], $result->amounts);
        $this->assertFalse($result->qualified_reported_amounts);
        $this->assertDatabaseCount('payment_financial_observations', 2);
    }

    public function test_pending_request_storage_failure_and_missing_receipt_remain_unqualified(): void
    {
        $parent = $this->rootOwned();
        $first = $this->financial($parent);
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER financial_storage_failure BEFORE INSERT ON payment_financial_observations BEGIN SELECT RAISE(ABORT, 'synthetic refusal'); END");
        } else {
            DB::unprepared("CREATE TRIGGER financial_storage_failure BEFORE INSERT ON payment_financial_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic refusal'");
        }
        try {
            $this->financial($parent);
            $this->fail('Expected storage refusal.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER financial_storage_failure');
        }
        $pending = $this->assessment($parent);
        $this->assertSame('pending', $pending->status);
        $this->assertGreaterThan($first->request_id, $pending->request_id);
        $this->assertDatabaseCount('payment_financial_observations', 1);
        DB::transaction(fn () => $this->invalid(fn () => $this->financial($parent)));
    }

    public function test_currency_and_current_scope_conflicts_block_prior_authority(): void
    {
        $parent = $this->rootOwned();
        $this->financial($parent);
        $id = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $parent->id)->firstOrFail()->receipt['transaction_id'];
        $this->entities[$id]['rail'] = 'bankAccount';
        $this->assertSame('currency_unqualified', $this->financial($parent)->status);
        $this->entities[$id]['rail'] = 'creditCard';
        $this->reports(fn () => $this->merchant->update(['authnet_transaction_key' => 'synthetic-drift']));
        $this->assertSame('scope_conflict', $this->financial($parent)->status);
        $this->assertFalse($this->assessment($parent)->qualified_reported_amounts);
    }

    public function test_retained_terminal_and_capture_evidence_block_regression_on_first_financial_read(): void
    {
        $parent = $this->rootOwned();
        $id = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $parent->id)->firstOrFail()->receipt['transaction_id'];
        $this->entities[$id]['status'] = 'voided';
        $this->entities[$id]['settle'] = 0;
        $this->assertSame('transition_conflict', $this->financial($parent)->status);
        $row = PaymentFinancialObservation::firstOrFail();
        $this->assertStringNotContainsString('transaction_status', $row->getRawOriginal('facts'));
        try {
            $row->update(['status' => 'qualified_reported']);
            $this->fail('Expected immutable evidence.');
        } catch (\LogicException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_owned_capture_blocks_authorized_and_expired_regressions_and_invalid_void_amounts(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        [$capture, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $this->lineage($capture, $attempt);
        $id = $attempt->receipt['transaction_id'];
        foreach (['authorizedPendingCapture', 'expired'] as $status) {
            $this->entities[$id]['status'] = $status;
            $this->assertFalse($this->financial($parent)->qualified_reported_amounts);
        }
        $this->entities[$id]['status'] = 'voided';
        $this->entities[$id]['settle'] = 1999;
        $this->assertFalse($this->financial($capture)->qualified_reported_amounts);
    }

    public function test_missing_owned_receipt_cannot_guess_target_or_create_request(): void
    {
        $parent = $this->prepare();
        $this->readFor($parent);
        $this->record($parent);
        $this->resetHttp();
        $this->invalid(fn () => $this->financial($parent));
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_financial_read_requests', 0);
    }

    public function test_older_terminal_association_cannot_be_hidden_by_latest_regressive_association(): void
    {
        $parent = $this->rootOwned();
        $id = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $parent->id)->firstOrFail()->receipt['transaction_id'];
        $this->entities[$id]['status'] = 'capturedPendingSettlement';
        $this->reports();
        $this->record($parent, $id);
        $this->assertSame('transition_conflict', $this->financial($parent)->status);
        $this->assertDatabaseCount('payment_financial_observations', 1);
    }
}
