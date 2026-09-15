<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\PostPaymentAccountingAction;
use App\Actions\Payments\ResolvePaymentUncertaintyAction;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Payments\PaymentAccountingJournal;
use App\Models\Payments\PaymentAccountingLine;
use App\Models\Payments\PaymentOperation;
use App\Services\Payments\ResolvePaymentAccounting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\Support\PaymentFinancialFixture;
use Tests\TestCase;

class PaymentAccountingTest extends TestCase
{
    use PaymentFinancialFixture;

    private function postAccounting()
    {
        return app(PostPaymentAccountingAction::class)->execute($this->order->uuid);
    }

    private function accounting()
    {
        return app(ResolvePaymentAccounting::class)->execute($this->order->uuid);
    }

    public function test_balanced_settlement_and_refund_post_once_per_entity_across_fresh_reads(): void
    {
        $parent = $this->rootOwned();
        $beforeOrder = $this->order->fresh()->getAttributes();
        $this->financial($parent);
        $first = $this->postAccounting();
        $this->assertSame('posted_reported', $first->status);
        $this->assertSame(2500, $first->amounts['net_settled_minor']);
        $this->assertSame('USD', $first->currency);
        $this->assertSame('sandbox', $first->environment);
        $this->assertSame($this->binding->canonical_account_key, $first->canonical_account_key);
        $this->assertSame($first->toArray(), $this->accounting()->toArray());
        $this->financial($parent);
        $this->assertSame($first->journal_ids, $this->postAccounting()->journal_ids);
        $settlement = PaymentAccountingJournal::firstOrFail();
        $this->assertSame('settlement', $settlement->kind);
        $this->assertDatabaseCount('payment_accounting_lines', 2);
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $this->lineage($refund, $attempt);
        $this->financial($refund);
        $this->financial($parent);
        $this->assertCount(1, $this->postAccounting()->journal_ids);
        $this->entities[$attempt->receipt['transaction_id']]['status'] = 'refundSettledSuccessfully';
        $this->reports();
        $this->financial($refund);
        $posted = $this->postAccounting();
        $this->assertCount(2, $posted->journal_ids);
        $this->assertSame(2000, $posted->amounts['net_settled_minor']);
        $this->assertSame($posted->journal_ids, $this->postAccounting()->journal_ids);
        $credit = PaymentAccountingJournal::where('kind', 'refund')->firstOrFail();
        $this->assertSame($settlement->id, $credit->parent_journal_id);
        $this->assertSame(500, $credit->amount_minor);
        foreach (PaymentAccountingJournal::all() as $journal) {
            $lines = PaymentAccountingLine::where('payment_accounting_journal_id', $journal->id)->get();
            $this->assertCount(2, $lines);
            $this->assertSame($journal->amount_minor, $lines->sum('debit_minor'));
            $this->assertSame($journal->amount_minor, $lines->sum('credit_minor'));
        }
        $this->assertSame(2000, PaymentAccountingLine::where('account', 'gateway_clearing')->sum('debit_minor') - PaymentAccountingLine::where('account', 'gateway_clearing')->sum('credit_minor'));
        $this->assertSame($beforeOrder, $this->order->fresh()->getAttributes());
        $this->assertFalse($posted->bank_cash_verified);
        $this->assertDatabaseCount('canonical_events', 0);
        Bus::assertNothingDispatched();
    }

    public function test_authorization_and_capture_pending_do_not_post_and_settled_capture_posts_shared_entity_once(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        $this->financial($parent);
        $this->assertSame('nothing_postable', $this->postAccounting()->status);
        [$capture, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $this->lineage($capture, $attempt);
        $this->financial($capture);
        $this->assertSame('nothing_postable', $this->postAccounting()->status);
        $this->entities[$attempt->receipt['transaction_id']]['status'] = 'settledSuccessfully';
        $this->reports();
        $this->financial($capture);
        $this->assertSame(2000, $this->postAccounting()->amounts['settled_minor']);
        $this->financial($parent);
        $this->postAccounting();
        $this->assertDatabaseCount('payment_accounting_journals', 1);
        $this->assertDatabaseCount('payment_accounting_lines', 2);
    }

    public function test_later_reversal_stale_or_failed_evidence_quarantines_but_never_erases_posted_history(): void
    {
        $parent = $this->rootOwned();
        $this->financial($parent);
        $posted = $this->postAccounting();
        $this->resetHttp();
        Http::fake(['*' => Http::response('unavailable', 503)]);
        $this->financial($parent);
        $this->assertSame('current_evidence_quarantined', $this->accounting()->status);
        $this->assertSame($posted->journal_ids, $this->accounting()->journal_ids);
        $this->assertNull($this->accounting()->amounts);
        $this->invalid(fn () => $this->postAccounting());
        $this->reports();
        $this->financial($parent);
        $this->assertSame('posted_reported', $this->accounting()->status);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(301));
        $this->assertNull($this->accounting()->amounts);
        $this->reports();
        $this->entities['8001']['status'] = 'chargeback';
        $this->financial($parent);
        $this->assertSame('current_evidence_quarantined', $this->accounting()->status);
        $this->invalid(fn () => $this->postAccounting());
        $this->assertDatabaseCount('payment_accounting_journals', 1);
        $this->assertDatabaseCount('payment_accounting_lines', 2);
    }

    public function test_storage_failure_rolls_back_complete_balanced_posting_and_history_is_immutable(): void
    {
        $parent = $this->rootOwned();
        $this->financial($parent);
        $calls = 0;
        PaymentAccountingLine::creating(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('Synthetic second line storage failure.');
            }
        });
        try {
            $this->postAccounting();
            $this->fail('Expected storage failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic second line storage failure.', $error->getMessage());
        }
        $this->assertDatabaseCount('payment_accounting_journals', 0);
        $this->assertDatabaseCount('payment_accounting_lines', 0);
        $this->postAccounting();
        $journal = PaymentAccountingJournal::firstOrFail();
        $this->assertStringNotContainsString('amount_minor', $journal->getRawOriginal('facts'));
        foreach ([$journal, PaymentAccountingLine::firstOrFail()] as $model) {
            try {
                $model->delete();
                $this->fail('Expected immutable history.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        DB::transaction(fn () => $this->invalid(fn () => $this->postAccounting()));
        $this->assertDatabaseCount('payment_accounting_journals', 1);
    }

    public function test_supported_uncertainty_overlay_permits_posting_without_releasing_execution(): void
    {
        $parent = $this->rootOwned();
        $operation = PaymentOperation::findOrFail($parent->payment_operation_id);
        $this->uncertainty($operation);
        $this->reports();
        $this->financial($parent);
        $this->invalid(fn () => $this->postAccounting());
        $resolved = app(ResolvePaymentUncertaintyAction::class)->execute($operation->uuid);
        $this->assertSame('resolved_reported', $resolved->status);
        $this->assertFalse($resolved->execution_released);
        $this->assertSame('posted_reported', $this->postAccounting()->status);
        $this->assertSame('uncertain', $operation->fresh()->state->value);
    }
}
