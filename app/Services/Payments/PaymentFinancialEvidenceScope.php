<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayTransactionReadData;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationEffectAssociation;
use App\Models\Payments\PaymentTransactionAssociation;

/** Requires the canonical account mutex; mutable ownership and evidence use current locking reads. */
class PaymentFinancialEvidenceScope
{
    public function __construct(private readonly PaymentOperationLineageScope $scope, private readonly ResolvePaymentOperationLineage $lineage,
        private readonly PaymentParentDispatchEvidence $owned, private readonly PaymentLedgerScope $ledger) {}

    public function snapshot(int $preparationId, int $scopeId): array
    {
        [$p,$op,$binding,,$scopeFingerprint] = $this->scope->current($preparationId);
        $result = $this->lineage->underLock($p, $scopeId);
        if (! in_array($result['status'], ['associated_only', 'effect_correlated_only'], true)) {
            $this->scope->reject();
        }
        $entity = $result['entity'];
        $attempt = $this->owned->assertCurrent($p, $entity);
        $attempts = PaymentDispatchAttempt::where('payment_intent_id', $op->payment_intent_id)->orderBy('id')->limit(257)->lockForUpdate()->get();
        if ($attempts->count() > 256) {
            $this->scope->reject();
        }
        $generation = [];
        foreach ($attempts as $candidate) {
            if ($candidate->status !== 'response_observed' || ($candidate->receipt['response_code'] ?? null) !== '1' || $candidate->completed_at === null) {
                $this->scope->reject();
            }
            $cp = PaymentDispatchPreparation::whereKey($candidate->payment_dispatch_preparation_id)->lockForUpdate()->firstOrFail();
            $cr = $this->lineage->underLock($cp, $scopeId);
            if (! in_array($cr['status'], ['associated_only', 'effect_correlated_only'], true)) {
                $this->scope->reject();
            }
            $candidateOperation = PaymentOperation::whereKey($candidate->payment_operation_id)->lockForUpdate()->firstOrFail();
            $generation[] = [$candidateOperation->state->value, $candidateOperation->uncertainty_fingerprint, $candidate->id, $candidate->status, $candidate->request_fingerprint, $candidate->completed_at->format('Y-m-d H:i:s.u'), $cr['fingerprint']];
        }
        $key = $this->ledger->fingerprint(['association_transaction_v1' => $entity['transaction_id']]);
        $effects = PaymentOperationEffectAssociation::where('payment_association_scope_id', $scopeId)->where('transaction_key', $key)->orderBy('id')->limit(257)->lockForUpdate()->get();
        if ($effects->count() > 256) {
            $this->scope->reject();
        }
        $captureAmount = null;
        $related = [];
        $retainedStatuses = [$entity['transaction_status']];
        $rootHistory = PaymentTransactionAssociation::where('payment_association_scope_id', $scopeId)->where('transaction_key', $key)
            ->orderBy('id')->limit(257)->lockForUpdate()->get();
        if ($rootHistory->count() > 256) {
            $this->scope->reject();
        }
        foreach ($rootHistory as $historical) {
            $retainedStatuses[] = $historical->facts['transaction_status'];
        }
        foreach ($effects as $historical) {
            $retainedStatuses[] = $historical->facts['entity']['transaction_status'];
        }
        foreach ($effects->groupBy('payment_dispatch_preparation_id') as $id => $rows) {
            $ep = PaymentDispatchPreparation::whereKey($id)->lockForUpdate()->firstOrFail();
            $er = $this->lineage->underLock($ep, $scopeId);
            if ($er['status'] !== 'effect_correlated_only') {
                $this->scope->reject();
            }
            $this->owned->assertCurrent($ep, $er['entity']);
            $related[] = $er['fingerprint'];
            $retainedStatuses[] = $er['entity']['transaction_status'];
            if ($rows->first()->effect_kind === 'capture') {
                $captureAmount = $rows->first()->amount_minor;
            }
        }
        $expectedAmount = $entity['transaction_type'] === 'authOnlyTransaction' ? ($captureAmount ?? $entity['authorized_amount_minor']) : $op->amount_minor;

        return ['preparation' => $p, 'operation' => $op, 'binding' => $binding, 'entity' => $entity, 'entity_key' => $key,
            'capture_amount' => $captureAmount, 'retained_statuses' => array_unique($retainedStatuses), 'expected_amount' => $expectedAmount, 'attempt' => $attempt,
            'fingerprint' => $this->ledger->fingerprint([$scopeFingerprint, $result['fingerprint'], $related, $generation, $attempt->request_fingerprint, $attempt->receipt]),
            'read' => new GatewayTransactionReadData($binding->id, $entity['transaction_id'], $entity['transaction_type'], $entity['original_transaction_id'],
                $op->amount_minor, $binding->currency, capture_financial_observation: true)];
    }
}
