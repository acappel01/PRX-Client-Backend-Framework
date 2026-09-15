<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentOperationLineageResolution;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationEffectAssociation;
use App\Models\Payments\PaymentTransactionAssociation;
use Illuminate\Support\Facades\DB;

/** Bounded current evidence graph. Root transaction identity and descendant operation effects stay distinct. */
class ResolvePaymentOperationLineage
{
    public function __construct(private readonly PaymentAssociationLock $lock, private readonly ResolvePaymentTransactionAssociation $roots,
        private readonly PaymentLedgerScope $ledger, private readonly PaymentOperationLineageScope $scope) {}

    public function execute(int $preparationId): PaymentOperationLineageResolution
    {
        if (DB::transactionLevel() !== 0) {
            $this->scope->reject();
        }
        $identity = PaymentDispatchPreparation::findOrFail($preparationId);

        return DB::transaction(function () use ($identity): PaymentOperationLineageResolution {
            $scopeId = $this->lock->acquire($identity->canonical_account_key, $identity->environment);
            $result = $this->underLock($identity, $scopeId);

            return $this->result($identity->id, $result);
        }, 3);
    }

    public function result(int $preparationId, array $result): PaymentOperationLineageResolution
    {
        return new PaymentOperationLineageResolution($preparationId, $result['status'], $result['evidence_ids'], $result['status'] === 'effect_correlated_only');
    }

    /** Internal caller holds canonical mutex; all graph reads lock current rows, including under RR. */
    public function underLock(PaymentDispatchPreparation $preparation, int $scopeId, array $visited = []): array
    {
        if ($this->lock->acquire($preparation->canonical_account_key, $preparation->environment) !== $scopeId) {
            $this->scope->reject();
        }
        if (count($visited) >= 8 || in_array($preparation->id, $visited, true)) {
            return $this->assessment('lineage_limit_quarantined');
        }
        $visited[] = $preparation->id;
        $operation = PaymentOperation::whereKey($preparation->payment_operation_id)->lockForUpdate()->firstOrFail();
        if ($operation->original_operation_id === null) {
            $root = $this->roots->underLock($preparation, $scopeId);
            if ($root->status !== 'associated_only') {
                return $this->assessment($root->status);
            }
            $rows = PaymentTransactionAssociation::whereIn('id', $root->association_ids)->orderBy('id')->lockForUpdate()->get();
            $latest = $rows->last();

            return $this->assessment('associated_only', [], $latest->facts,
                $this->ledger->fingerprint($rows->map(fn ($row) => [$row->id, $row->evidence_fingerprint])->all()), [$operation->id]);
        }
        $rows = PaymentOperationEffectAssociation::where('payment_association_scope_id', $scopeId)
            ->where('payment_dispatch_preparation_id', $preparation->id)->orderBy('id')->limit(257)->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            return $this->assessment('missing');
        }
        if ($rows->count() > 256) {
            return $this->assessment('evidence_limit_quarantined', $rows->take(256)->pluck('id')->all());
        }
        $ids = $rows->pluck('id')->all();
        if ($rows->pluck('transaction_key')->unique()->count() !== 1 || $rows->pluck('parent_preparation_id')->unique()->count() !== 1
            || $rows->pluck('payment_dispatch_attempt_id')->unique()->count() !== 1) {
            return $this->assessment('conflict_quarantined', $ids);
        }
        $latest = $rows->last();
        $parent = PaymentDispatchPreparation::whereKey($latest->parent_preparation_id)->lockForUpdate()->firstOrFail();
        if ($parent->payment_operation_id !== $operation->original_operation_id || $parent->canonical_account_key !== $preparation->canonical_account_key
            || $parent->environment !== $preparation->environment) {
            return $this->assessment('conflict_quarantined', $ids);
        }
        $parentResult = $this->underLock($parent, $scopeId, $visited);
        if (! in_array($parentResult['status'], ['associated_only', 'effect_correlated_only'], true)) {
            return $this->assessment('parent_'.$parentResult['status'], $ids);
        }
        $related = PaymentOperationEffectAssociation::where('payment_association_scope_id', $scopeId)
            ->where(function ($query) use ($latest): void {
                $query->where('transaction_key', $latest->transaction_key);
                if ($latest->effect_kind === 'refund') {
                    $query->orWhere(fn ($q) => $q->where('effect_kind', 'refund')->where('parent_transaction_key', $latest->parent_transaction_key));
                }
            })->orderBy('id')->limit(257)->lockForUpdate()->get();
        if ($related->count() > 256) {
            return $this->assessment('evidence_limit_quarantined', $ids);
        }
        $sameEntity = $related->where('transaction_key', $latest->transaction_key);
        // Capture and void legitimately share an entity; competing actors for the same effect do not.
        if ($sameEntity->where('effect_kind', $latest->effect_kind)->pluck('payment_operation_id')->unique()->count() > 1
            || ($latest->effect_kind === 'refund' && $sameEntity->pluck('payment_operation_id')->unique()->count() > 1)) {
            return $this->assessment('conflict_quarantined', $ids);
        }
        if ($latest->effect_kind === 'refund') {
            if (PaymentTransactionAssociation::where('payment_association_scope_id', $scopeId)->where('transaction_key', $latest->transaction_key)->lockForUpdate()->exists()) {
                return $this->assessment('conflict_quarantined', $ids);
            }
            $refunds = $related->where('effect_kind', 'refund')->where('parent_transaction_key', $latest->parent_transaction_key);
            if ($refunds->groupBy('payment_operation_id')->contains(fn ($group) => $group->pluck('transaction_key')->unique()->count() > 1)
                || $refunds->groupBy('transaction_key')->contains(fn ($group) => $group->pluck('payment_operation_id')->unique()->count() > 1)) {
                return $this->assessment('conflict_quarantined', $ids);
            }
            $claims = $refunds
                ->groupBy('payment_operation_id')->map(fn ($group) => $group->max('amount_minor'))->sum();
            if ($claims > $parentResult['entity']['settlement_amount_minor']) {
                return $this->assessment('refund_bound_quarantined', $ids);
            }
        }
        if ($rows->contains(fn ($row) => ! $row->currency_qualified)) {
            return $this->assessment('currency_unqualified', $ids);
        }

        return $this->assessment('effect_correlated_only', $ids, $latest->facts['entity'],
            $this->ledger->fingerprint([$parentResult['fingerprint'], $rows->map(fn ($row) => [$row->id, $row->evidence_fingerprint])->all()]),
            array_merge([$operation->id], $parentResult['ancestor_operation_ids']));
    }

    private function assessment(string $status, array $ids = [], ?array $entity = null, ?string $fingerprint = null, array $ancestors = []): array
    {
        return ['status' => $status, 'evidence_ids' => $ids, 'entity' => $entity, 'fingerprint' => $fingerprint, 'ancestor_operation_ids' => $ancestors];
    }
}
