<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentDispatchLineageResolver;
use App\Data\Payments\PaymentDispatchOriginal;
use App\Models\Payments\PaymentDispatchPreparation;

/** Derives a parent transaction from current durable evidence; no arbitrary original ID input. */
class ResolvePaymentDispatchOriginal implements PaymentDispatchLineageResolver
{
    public function __construct(private readonly PaymentAssociationLock $lock, private readonly PaymentOperationLineageScope $scope,
        private readonly ResolvePaymentOperationLineage $resolver, private readonly PaymentParentDispatchEvidence $parentProof) {}

    public function resolve(PaymentDispatchPreparation $preparation): PaymentDispatchOriginal
    {
        $scopeId = $this->lock->acquire($preparation->canonical_account_key, $preparation->environment);
        [$current, $operation, $binding, $parent] = $this->scope->current($preparation->id);
        if ($parent === null) {
            $this->scope->reject();
        }
        $parentPreparation = PaymentDispatchPreparation::where('payment_operation_id', $parent->id)->lockForUpdate()->first();
        if ($parentPreparation === null || $parentPreparation->canonical_account_key !== $current->canonical_account_key
            || $parentPreparation->environment !== $current->environment) {
            $this->scope->reject();
        }
        $this->scope->current($parentPreparation->id);
        $result = $this->resolver->underLock($parentPreparation, $scopeId);
        if (! in_array($result['status'], ['associated_only', 'effect_correlated_only'], true)) {
            $this->scope->reject();
        }

        $entity = $result['entity'];
        $allowedStatuses = match ($operation->purpose->value) {
            'capture' => ['authorizedPendingCapture'],
            'refund' => ['settledSuccessfully'],
            'void' => ['authorizedPendingCapture', 'capturedPendingSettlement'],
            default => [],
        };
        $bound = $operation->purpose->value === 'capture' ? $entity['authorized_amount_minor'] :
            ($operation->purpose->value === 'refund' ? $entity['settlement_amount_minor'] : $parent->amount_minor);
        if (! in_array($entity['transaction_status'], $allowedStatuses, true) || $operation->amount_minor > $bound) {
            $this->scope->reject();
        }

        $this->parentProof->assertCurrent($parentPreparation, $result['entity']);

        return new PaymentDispatchOriginal($result['entity']['transaction_id'], $parentPreparation->id, $parent->id,
            $binding->canonical_account_key, $binding->environment, $result['fingerprint'], $result['ancestor_operation_ids']);
    }
}
