<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentAccountingResult;
use App\Models\Commerce\Order;
use App\Models\Payments\PaymentAccountingJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Existing entries remain history even when fresh financial support becomes unavailable. */
class ResolvePaymentAccounting
{
    public function __construct(private readonly ResolveOrderFinancialEvidence $financial, private readonly PaymentAccountingScope $scope) {}

    public function execute(string $orderUuid): PaymentAccountingResult
    {
        if (DB::transactionLevel() !== 0) {
            $this->scope->reject();
        }
        $order = Order::where('uuid', $orderUuid)->firstOrFail();
        $keys = $this->financial->scopeKeys($order);
        if ($keys === null) {
            return new PaymentAccountingResult($order->uuid, 'evidence_limit_quarantined');
        }

        return DB::transaction(function () use ($order, $keys): PaymentAccountingResult {
            $assessment = $this->financial->underLock($order, $keys);
            $journals = PaymentAccountingJournal::where('order_id', $order->id)->orderBy('id')->limit(129)->lockForUpdate()->get();
            $ids = $journals->take(128)->pluck('id')->all();
            if ($journals->count() > 128) {
                return new PaymentAccountingResult($order->uuid, 'evidence_limit_quarantined', $ids);
            }
            try {
                $candidates = $this->scope->candidates($order->fresh(), $assessment);
                foreach ($journals as $journal) {
                    $key = $journal->payment_association_scope_id.':'.$journal->entity_key;
                    if (! isset($candidates[$key])) {
                        $this->scope->reject();
                    }
                    $this->scope->assertJournal($journal, $candidates[$key], $order);
                    if ($journal->kind === 'refund') {
                        $parent = $journals->firstWhere('id', $journal->parent_journal_id);
                        if ($parent === null || $parent->kind !== 'settlement' || $parent->entity_key !== $candidates[$key]['parent_entity_key']
                            || $parent->currency !== $journal->currency || $parent->payment_association_scope_id !== $journal->payment_association_scope_id
                            || $journals->where('parent_journal_id', $parent->id)->sum('amount_minor') > $parent->amount_minor) {
                            $this->scope->reject();
                        }
                    }
                }
            } catch (ValidationException) {
                return new PaymentAccountingResult($order->uuid, 'current_evidence_quarantined', $ids);
            }
            if (count($candidates) !== $journals->count()) {
                return new PaymentAccountingResult($order->uuid, 'posting_required', $ids, currency: $assessment->currency, environment: $assessment->environment, canonical_account_key: $assessment->canonical_account_key);
            }

            return new PaymentAccountingResult($order->uuid, $ids === [] ? 'nothing_postable' : 'posted_reported', $ids,
                ['settled_minor' => $assessment->amounts['settled_minor'], 'refunded_minor' => $assessment->amounts['refunded_minor'],
                    'net_settled_minor' => $assessment->amounts['net_settled_minor']],
                currency: $assessment->currency, environment: $assessment->environment, canonical_account_key: $assessment->canonical_account_key);
        }, 3);
    }
}
