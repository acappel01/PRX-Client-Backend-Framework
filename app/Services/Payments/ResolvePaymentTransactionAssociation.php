<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentAssociationResolution;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentOperationEffectAssociation;
use App\Models\Payments\PaymentTransactionAssociation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Recompute the whole connected component using current locking reads. No sticky first winner. */
class ResolvePaymentTransactionAssociation
{
    public function __construct(private readonly PaymentAssociationLock $lock) {}

    public function execute(int $preparationId): PaymentAssociationResolution
    {
        if (DB::transactionLevel() !== 0) {
            throw ValidationException::withMessages(['payment' => 'Association assessment requires its own current transaction.']);
        }
        $preparation = PaymentDispatchPreparation::findOrFail($preparationId);

        return DB::transaction(function () use ($preparation): PaymentAssociationResolution {
            $scopeId = $this->lock->acquire($preparation->canonical_account_key, $preparation->environment);

            return $this->underLock($preparation, $scopeId);
        }, 3);
    }

    /** Internal orchestration owns the account mutex until commit. */
    public function underLock(PaymentDispatchPreparation $preparation, int $scopeId): PaymentAssociationResolution
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Current evidence requires an account transaction.');
        }
        if ($this->lock->acquire($preparation->canonical_account_key, $preparation->environment) !== $scopeId) {
            throw new \LogicException('Association account scope mismatch.');
        }
        $operations = [$preparation->payment_operation_id => true];
        $transactions = [];
        do {
            $before = count($operations) + count($transactions);
            $component = PaymentTransactionAssociation::where('payment_association_scope_id', $scopeId)
                ->where(function ($query) use ($operations, $transactions): void {
                    $query->whereIn('payment_operation_id', array_keys($operations));
                    if ($transactions !== []) {
                        $query->orWhereIn('transaction_key', array_keys($transactions));
                    }
                })->orderBy('id')->limit(257)->lockForUpdate()->get();
            if ($component->count() > 256) {
                return new PaymentAssociationResolution($preparation->id, 'evidence_limit_quarantined', $component->take(256)->pluck('id')->all());
            }
            foreach ($component as $row) {
                $operations[$row->payment_operation_id] = true;
                $transactions[$row->transaction_key] = true;
            }
        } while ($before !== count($operations) + count($transactions));
        $status = $component->isEmpty() ? 'missing' : 'associated_only';
        if ($component->contains(fn ($row) => ! $row->currency_qualified)) {
            $status = 'currency_unqualified';
        }
        if (PaymentOperationEffectAssociation::where('payment_association_scope_id', $scopeId)
            ->where('effect_kind', 'refund')->whereIn('transaction_key', array_keys($transactions))->lockForUpdate()->exists()) {
            $status = 'conflict_quarantined';
        }
        if (count($operations) > 1 || count($transactions) > 1) {
            $status = 'conflict_quarantined';
        }

        return new PaymentAssociationResolution($preparation->id, $status, $component->pluck('id')->all());
    }
}
