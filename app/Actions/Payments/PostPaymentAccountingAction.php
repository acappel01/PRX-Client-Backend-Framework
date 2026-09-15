<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentAccountingResult;
use App\Models\Commerce\Order;
use App\Models\Payments\PaymentAccountingJournal;
use App\Models\Payments\PaymentAccountingLine;
use App\Services\Payments\PaymentAccountingScope;
use App\Services\Payments\ResolveOrderFinancialEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal authorized operator call; atomic balanced control postings, no bank/revenue/order mutation. */
class PostPaymentAccountingAction
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
            $this->scope->reject();
        }

        return DB::transaction(function () use ($order, $keys): PaymentAccountingResult {
            $assessment = $this->financial->underLock($order, $keys);
            $candidates = $this->scope->candidates($order->fresh(), $assessment);
            $prior = PaymentAccountingJournal::where('order_id', $order->id)->orderBy('id')->limit(129)->lockForUpdate()->get();
            if ($prior->count() > 128) {
                $this->scope->reject();
            }
            foreach ($prior as $journal) {
                $key = $journal->payment_association_scope_id.':'.$journal->entity_key;
                if (! isset($candidates[$key])) {
                    $this->scope->reject();
                }
                $this->scope->assertJournal($journal, $candidates[$key], $order);
            }
            $ids = [];
            foreach ($candidates as $candidate) {
                $existing = PaymentAccountingJournal::where('payment_association_scope_id', $candidate['scope_id'])
                    ->where('entity_key', $candidate['entity_key'])->lockForUpdate()->first();
                $parent = null;
                if ($candidate['kind'] === 'refund') {
                    $parent = PaymentAccountingJournal::where('payment_association_scope_id', $candidate['scope_id'])
                        ->where('entity_key', $candidate['parent_entity_key'])->lockForUpdate()->first();
                    if ($parent === null || $parent->kind !== 'settlement' || $parent->order_id !== $order->id || $parent->currency !== $candidate['currency']
                        || ! isset($candidates[$candidate['scope_id'].':'.$candidate['parent_entity_key']])) {
                        $this->scope->reject();
                    }
                    $refunds = PaymentAccountingJournal::where('parent_journal_id', $parent->id)->orderBy('id')->limit(129)->lockForUpdate()->get();
                    if ($refunds->count() > 128 || $refunds->sum('amount_minor') + ($existing === null ? $candidate['amount_minor'] : 0) > $parent->amount_minor) {
                        $this->scope->reject();
                    }
                }
                if ($existing !== null) {
                    $this->scope->assertJournal($existing, $candidate, $order);
                    if ($existing->parent_journal_id !== $parent?->id) {
                        $this->scope->reject();
                    }
                    $ids[] = $existing->id;

                    continue;
                }
                $journal = PaymentAccountingJournal::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'order_uuid' => $order->uuid,
                    'payment_association_scope_id' => $candidate['scope_id'], 'payment_financial_observation_id' => $candidate['observation_id'],
                    'parent_journal_id' => $parent?->id, 'entity_key' => $candidate['entity_key'], 'kind' => $candidate['kind'],
                    'currency' => $candidate['currency'], 'amount_minor' => $candidate['amount_minor'], 'economic_fingerprint' => $candidate['economic_fingerprint'],
                    'facts' => $candidate['facts'], 'posted_at' => CarbonImmutable::now('UTC')]);
                $debit = $candidate['kind'] === 'settlement' ? 'gateway_clearing' : 'customer_payment_control';
                foreach (['gateway_clearing', 'customer_payment_control'] as $account) {
                    PaymentAccountingLine::create(['payment_accounting_journal_id' => $journal->id, 'account' => $account,
                        'debit_minor' => $account === $debit ? $candidate['amount_minor'] : 0,
                        'credit_minor' => $account === $debit ? 0 : $candidate['amount_minor']]);
                }
                $this->scope->assertJournal($journal, $candidate, $order);
                $ids[] = $journal->id;
            }

            return new PaymentAccountingResult($order->uuid, $ids === [] ? 'nothing_postable' : 'posted_reported', $ids,
                ['settled_minor' => $assessment->amounts['settled_minor'], 'refunded_minor' => $assessment->amounts['refunded_minor'],
                    'net_settled_minor' => $assessment->amounts['net_settled_minor']],
                currency: $assessment->currency, environment: $assessment->environment, canonical_account_key: $assessment->canonical_account_key);
        }, 3);
    }
}
