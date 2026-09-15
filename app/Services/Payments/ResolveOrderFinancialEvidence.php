<?php

namespace App\Services\Payments;

use App\Data\Payments\OrderFinancialResolution;
use App\Models\Commerce\Order;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Read-only projection, with shared provider entities counted once and no old-evidence fallback. */
class ResolveOrderFinancialEvidence
{
    public function __construct(private readonly PaymentAssociationLock $lock, private readonly PaymentLedgerScope $scope,
        private readonly ResolvePaymentFinancialEvidence $financial) {}

    public function execute(string $orderUuid): OrderFinancialResolution
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Order financial assessment requires its own transaction.');
        }
        $identity = Order::where('uuid', $orderUuid)->firstOrFail();
        $keys = $this->scopeKeys($identity);
        if ($keys === null) {
            return new OrderFinancialResolution($identity->uuid, $identity->currency, 'evidence_limit_quarantined');
        }

        return DB::transaction(fn () => $this->underLock($identity, $keys), 3);
    }

    /** Discover bounded lock identities; underLock rejects any newly introduced account. */
    public function scopeKeys(Order $identity): ?array
    {
        // Discover mutex identities before the transaction. Recheck under the order lock;
        // a newly introduced account cannot silently join this assessment snapshot.
        $preparations = PaymentDispatchPreparation::whereIn('payment_operation_id', PaymentOperation::whereIn('payment_intent_id',
            PaymentIntent::where('order_id', $identity->id)->select('id'))->select('id'))->limit(129)->get();
        if ($preparations->count() > 128) {
            return null;
        }

        return $preparations->map(fn ($p) => [$p->canonical_account_key, $p->environment])
            ->unique(fn ($pair) => implode(':', $pair))->sortBy(fn ($pair) => implode(':', $pair))->values()->all();

    }

    /** Caller owns the transaction; these account/order locks remain held through any posting. */
    public function underLock(Order $identity, array $keys): OrderFinancialResolution
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Order assessment requires a transaction.');
        }
        $scopes = [];
        foreach ($keys as [$account, $environment]) {
            $scopes[$account.':'.$environment] = $this->lock->acquire($account, $environment);
        }
        $order = Order::whereKey($identity->id)->lockForUpdate()->firstOrFail();
        $result = fn (string $status) => new OrderFinancialResolution($order->uuid, $order->currency, $status);
        $intents = PaymentIntent::where('order_id', $order->id)->orderBy('id')->limit(129)->lockForUpdate()->get();
        if ($intents->count() > 128) {
            return $result('evidence_limit_quarantined');
        }
        $attempts = PaymentDispatchAttempt::whereIn('payment_intent_id', $intents->pluck('id'))->orderBy('id')->limit(129)->lockForUpdate()->get();
        if ($attempts->isEmpty()) {
            return $result('missing');
        }
        if ($attempts->count() > 128) {
            return $result('evidence_limit_quarantined');
        }
        if ($attempts->pluck('payment_intent_id')->unique()->count() !== 1) {
            return $result('multiple_intents_quarantined');
        }
        $intent = $intents->firstWhere('id', $attempts->first()->payment_intent_id);
        try {
            $this->scope->assertCurrent($intent);
        } catch (ValidationException) {
            return $result('scope_conflict');
        }
        if ($intent->currency !== $order->currency) {
            return $result('unresolved');
        }
        $uncertainOperations = PaymentOperation::where('payment_intent_id', $intent->id)->where('state', 'uncertain')->orderBy('id')->limit(129)->lockForUpdate()->get();
        if ($uncertainOperations->count() > 128) {
            return $result('evidence_limit_quarantined');
        }
        foreach ($uncertainOperations as $uncertain) {
            $uncertainPreparation = PaymentDispatchPreparation::where('payment_operation_id', $uncertain->id)->lockForUpdate()->first();
            $uncertainScope = $uncertainPreparation === null ? null : ($scopes[$uncertainPreparation->canonical_account_key.':'.$uncertainPreparation->environment] ?? null);
            if ($uncertainScope === null || app(ResolvePaymentUncertaintyEvidence::class)->underLock($uncertain, $uncertainScope)->status !== 'resolved_reported') {
                return $result('unresolved');
            }
        }
        $entities = [];
        foreach ($attempts as $attempt) {
            $receipt = $attempt->receipt;
            if ($attempt->status !== 'response_observed' || ($receipt['response_code'] ?? null) !== '1'
                || ! is_string($receipt['transaction_id'] ?? null) || ! preg_match('/\A[1-9][0-9]{0,31}\z/', $receipt['transaction_id'])) {
                return $result('unresolved');
            }
            $preparation = PaymentDispatchPreparation::whereKey($attempt->payment_dispatch_preparation_id)->lockForUpdate()->firstOrFail();
            $scopeKey = $preparation->canonical_account_key.':'.$preparation->environment;
            if (! isset($scopes[$scopeKey]) || $preparation->payment_operation_id !== $attempt->payment_operation_id) {
                return $result('scope_conflict');
            }
            $entityKey = $scopeKey.':'.$this->scope->fingerprint(['association_transaction_v1' => $receipt['transaction_id']]);
            // Later capture/void attempts replace the root's view of the same entity.
            $entities[$entityKey] = [$preparation, $scopes[$scopeKey], $attempt];
        }
        $totals = array_fill_keys(['authorized_minor', 'captured_pending_minor', 'settled_minor', 'refund_pending_minor', 'refunded_minor', 'voided_minor'], 0);
        $observations = [];
        foreach ($entities as [$preparation, $scopeId, $attempt]) {
            $read = $this->financial->underLock($preparation, $scopeId);
            if (! $read->qualified_reported_amounts || $read->status !== 'qualified_reported' || $read->currency !== $order->currency
                || $read->canonical_account_key !== $preparation->canonical_account_key || $read->environment !== $preparation->environment
                || $read->entity_key !== $this->scope->fingerprint(['association_transaction_v1' => $attempt->receipt['transaction_id']])
                || $read->request_started_at === null || $attempt->completed_at === null || $read->request_started_at->lessThan($attempt->completed_at)) {
                return $result('entity_unqualified');
            }
            foreach ($totals as $key => $total) {
                $value = $read->amounts[$key] ?? null;
                if (! is_int($value) || $value < 0 || $value > 999999999999) {
                    return $result('amount_conflict');
                }
                $totals[$key] += $value;
            }
            $observations[] = $read->observation_id;
        }
        // The immutable intent is the obligation bound; no available credit or retry
        // capacity is inferred by subtracting refunds, voids or declined attempts.
        if ($intent->amount_minor < $totals['authorized_minor'] + $totals['captured_pending_minor'] + $totals['settled_minor']
            || $totals['settled_minor'] < $totals['refund_pending_minor'] + $totals['refunded_minor']) {
            return $result('amount_conflict');
        }
        $totals['net_settled_minor'] = $totals['settled_minor'] - $totals['refunded_minor'];

        return new OrderFinancialResolution($order->uuid, $order->currency, 'qualified_reported', $totals,
            array_values(array_unique($observations)), true, environment: $intent->environment->value,
            canonical_account_key: $preparation->canonical_account_key);
    }
}
