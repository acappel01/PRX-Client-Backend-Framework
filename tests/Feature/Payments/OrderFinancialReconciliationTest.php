<?php

namespace Tests\Feature\Payments;

use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Payments\PaymentFinancialReadRequest;
use App\Services\Payments\ResolveOrderFinancialEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentFinancialFixture;
use Tests\TestCase;

class OrderFinancialReconciliationTest extends TestCase
{
    use PaymentFinancialFixture;

    private function orderAssessment()
    {
        return app(ResolveOrderFinancialEvidence::class)->execute($this->order->uuid);
    }

    public function test_settled_sale_and_refund_have_distinct_entities_and_net_reported_value(): void
    {
        $parent = $this->rootOwned();
        $before = $this->order->fresh()->getAttributes();
        $this->financial($parent);
        $this->assertSame(2500, $this->orderAssessment()->amounts['settled_minor']);
        [$refund, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Refund, 500);
        $this->assertFalse($this->orderAssessment()->qualified_reported_amounts);
        $this->lineage($refund, $attempt);
        $this->financial($refund);
        $this->financial($parent);
        $pending = $this->orderAssessment();
        $this->assertSame('qualified_reported', $pending->status);
        $this->assertSame(500, $pending->amounts['refund_pending_minor']);
        $this->assertSame(2500, $pending->amounts['net_settled_minor']);
        $this->entities[$attempt->receipt['transaction_id']]['status'] = 'refundSettledSuccessfully';
        $this->reports();
        $this->financial($refund);
        $settled = $this->orderAssessment();
        $this->assertTrue($settled->qualified_reported_amounts);
        $this->assertSame(2500, $settled->amounts['settled_minor']);
        $this->assertSame(500, $settled->amounts['refunded_minor']);
        $this->assertSame(2000, $settled->amounts['net_settled_minor']);
        $this->assertCount(2, $settled->observation_ids);
        $this->assertSame('sandbox', $settled->environment);
        $this->assertSame($this->binding->canonical_account_key, $settled->canonical_account_key);
        $this->assertFalse($settled->bank_cash_verified);
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->assertDatabaseCount('canonical_events', 0);
        Bus::assertNothingDispatched();
    }

    public function test_partial_capture_and_void_count_the_shared_entity_once(): void
    {
        $parent = $this->rootOwned(PaymentOperationPurpose::Authorize);
        $this->financial($parent);
        $this->assertSame(2500, $this->orderAssessment()->amounts['authorized_minor']);
        [$capture, $attempt] = $this->childOwned($parent, PaymentOperationPurpose::Capture, 2000);
        $this->assertNull($this->orderAssessment()->amounts);
        $this->lineage($capture, $attempt);
        $this->financial($capture);
        $captured = $this->orderAssessment();
        $this->assertTrue($captured->qualified_reported_amounts);
        $this->assertSame(2000, $captured->amounts['captured_pending_minor']);
        $this->assertSame(0, $captured->amounts['authorized_minor']);
        $this->assertCount(1, $captured->observation_ids);
        [$void, $voidAttempt] = $this->childOwned($capture, PaymentOperationPurpose::Void, 2000);
        $this->lineage($void, $voidAttempt);
        $this->financial($void);
        $voided = $this->orderAssessment();
        $this->assertSame(2000, $voided->amounts['voided_minor']);
        $this->assertSame(0, $voided->amounts['captured_pending_minor']);
        $this->assertSame(0, $voided->amounts['net_settled_minor']);
        $this->assertCount(1, $voided->observation_ids);
    }

    public function test_new_unknown_attempt_stale_read_and_order_drift_never_return_old_totals(): void
    {
        $parent = $this->rootOwned();
        $this->financial($parent);
        $this->assertTrue($this->orderAssessment()->qualified_reported_amounts);
        $started = CarbonImmutable::now();
        CarbonImmutable::setTestNow($started->addSeconds(301));
        $this->assertNull($this->orderAssessment()->amounts);
        CarbonImmutable::setTestNow($started);
        $this->order->update(['total_amount' => '26.00']);
        $this->assertSame('scope_conflict', $this->orderAssessment()->status);
        $this->order->update(['total_amount' => '25.00']);
        $refund = $this->childPreparation($parent, PaymentOperationPurpose::Refund, 500);
        $attempt = $this->dispatchOwned($refund);
        // Model a persisted process interruption; an older good sale read cannot hide it.
        DB::table('payment_dispatch_attempts')->where('id', $attempt->id)->update(['status' => 'claimed', 'completed_at' => null, 'receipt' => null]);
        $this->resetHttp();
        $result = $this->orderAssessment();
        $this->assertSame('unresolved', $result->status);
        $this->assertNull($result->amounts);
        Http::assertNothingSent();
    }

    public function test_failed_latest_read_and_pending_read_block_the_order_projection(): void
    {
        $parent = $this->rootOwned();
        $this->financial($parent);
        $this->resetHttp();
        Http::fake(['*' => Http::response('unavailable', 503)]);
        $this->financial($parent);
        $this->assertFalse($this->orderAssessment()->qualified_reported_amounts);
        $this->reports();
        $this->financial($parent);
        $this->assertTrue($this->orderAssessment()->qualified_reported_amounts);
        $request = PaymentFinancialReadRequest::orderByDesc('id')->firstOrFail()->replicate();
        $request->save();
        $this->assertNull($this->orderAssessment()->amounts);
    }
}
