<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentUncertaintyResolutionResult;
use App\Enums\Payments\PaymentOperationState;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentUncertaintyResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Audit existence is insufficient: current owned financial evidence must still qualify. */
class ResolvePaymentUncertaintyEvidence
{
    public function __construct(private readonly PaymentAssociationLock $lock, private readonly PaymentFinancialEvidenceScope $scope,
        private readonly ResolvePaymentFinancialEvidence $financial, private readonly PaymentLedgerScope $ledger) {}

    public function execute(string $operationUuid): PaymentUncertaintyResolutionResult
    {
        if (DB::transactionLevel() !== 0) {
            $this->reject();
        }
        $operation = PaymentOperation::where('uuid', strtolower($operationUuid))->firstOrFail();
        $p = PaymentDispatchPreparation::where('payment_operation_id', $operation->id)->first();
        if ($p === null) {
            return new PaymentUncertaintyResolutionResult($operation->uuid, 'unresolved');
        }

        return DB::transaction(function () use ($operation, $p) {
            $sid = $this->lock->acquire($p->canonical_account_key, $p->environment);

            return $this->underLock($operation, $sid);
        }, 3);
    }

    public function underLock(PaymentOperation $operation, int $scopeId): PaymentUncertaintyResolutionResult
    {
        if (DB::transactionLevel() === 0) {
            $this->reject();
        }
        PaymentIntent::whereKey($operation->payment_intent_id)->lockForUpdate()->firstOrFail();
        $operation = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
        $result = new PaymentUncertaintyResolutionResult($operation->uuid, $operation->state === PaymentOperationState::Uncertain ? 'unresolved' : 'not_uncertain');
        if ($operation->state !== PaymentOperationState::Uncertain) {
            return $result;
        }
        $audit = PaymentUncertaintyResolution::where('payment_operation_id', $operation->id)->lockForUpdate()->first();
        if ($audit === null) {
            return $result;
        }
        $result->resolution_id = $audit->id;
        $result->status = 'resolution_unqualified';
        try {
            [$current,$scope,$read] = $this->qualifyUnderLock($operation, $scopeId);
        } catch (ValidationException) {
            return $result;
        }
        if (! hash_equals($audit->uncertainty_fingerprint, $current->uncertainty_fingerprint)
         || $audit->payment_dispatch_attempt_id !== $scope['attempt']->id
         || ! hash_equals($audit->evidence_fingerprint, $this->ledger->fingerprint($audit->evidence))) {
            return $result;
        }
        $evidence = $audit->evidence;
        if (($evidence['entity_key'] ?? null) !== $read->entity_key || ($evidence['canonical_account_key'] ?? null) !== $read->canonical_account_key
         || ($evidence['environment'] ?? null) !== $read->environment || ($evidence['currency'] ?? null) !== $read->currency) {
            return $result;
        }
        $result->status = 'resolved_reported';
        $result->currently_qualified = true;
        $result->financial_request_id = $read->request_id;
        $result->financial_observation_id = $read->observation_id;
        $result->classification = $read->classification;

        return $result;
    }

    /** Internal eligibility for the first immutable audit, not a standalone resolution grant. */
    public function qualifyUnderLock(PaymentOperation $operation, int $scopeId): array
    {
        if (DB::transactionLevel() === 0) {
            $this->reject();
        }
        PaymentIntent::whereKey($operation->payment_intent_id)->lockForUpdate()->firstOrFail();
        $operation = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
        if ($operation->state !== PaymentOperationState::Uncertain || $operation->uncertain_at === null || $operation->uncertainty_evidence === null
         || ! is_string($operation->uncertainty_fingerprint) || ! hash_equals($operation->uncertainty_fingerprint, $this->ledger->fingerprint($operation->uncertainty_evidence))) {
            $this->reject();
        }
        $p = PaymentDispatchPreparation::where('payment_operation_id', $operation->id)->lockForUpdate()->first();
        if ($p === null || $this->lock->acquire($p->canonical_account_key, $p->environment) !== $scopeId) {
            $this->reject();
        }
        $scope = $this->scope->snapshot($p->id, $scopeId);
        $read = $this->financial->underLock($p, $scopeId);
        // Legacy uncertainty timestamps have second precision. Require the next complete second,
        // as well as current generation fingerprints, rather than guessing same-second ordering.
        if (! $read->qualified_reported_amounts || $read->status !== 'qualified_reported' || $read->request_id === null || $read->observation_id === null
         || $read->request_started_at === null || $read->request_started_at->lessThan($operation->uncertain_at->addSecond())
         || $read->entity_key !== $scope['entity_key'] || $read->currency !== $scope['binding']->currency
         || $read->canonical_account_key !== $p->canonical_account_key || $read->environment !== $p->environment) {
            $this->reject();
        }
        $facts = $operation->uncertainty_evidence;
        if (($facts['gateway_transaction_reference'] ?? null) !== null && $facts['gateway_transaction_reference'] !== $scope['entity']['transaction_id']) {
            $this->reject();
        }
        if (($facts['original_gateway_transaction_reference'] ?? null) !== null && $facts['original_gateway_transaction_reference'] !== $scope['attempt']->request_facts['original_transaction_id']) {
            $this->reject();
        }

        return [$operation, $scope, $read];
    }

    public function reject(): never
    {
        throw ValidationException::withMessages(['payment' => 'Uncertainty lacks current owned post-uncertainty reporting evidence.']);
    }
}
