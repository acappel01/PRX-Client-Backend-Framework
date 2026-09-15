<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentFinancialResolution;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentFinancialObservation;
use App\Models\Payments\PaymentFinancialReadRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Latest requested read per entity wins; pending, failed or stale reads never fall back. */
class ResolvePaymentFinancialEvidence
{
    public const FRESH_SECONDS = 300;

    public function __construct(private readonly PaymentAssociationLock $lock, private readonly PaymentFinancialEvidenceScope $scope) {}

    public function execute(int $preparationId): PaymentFinancialResolution
    {
        if (DB::transactionLevel() !== 0) {
            throw ValidationException::withMessages(['payment' => 'Financial assessment requires its own transaction.']);
        }
        $p = PaymentDispatchPreparation::findOrFail($preparationId);

        return DB::transaction(function () use ($p) {
            $id = $this->lock->acquire($p->canonical_account_key, $p->environment);

            return $this->underLock($p, $id);
        }, 3);
    }

    public function underLock(PaymentDispatchPreparation $p, int $scopeId): PaymentFinancialResolution
    {
        if ($this->lock->acquire($p->canonical_account_key, $p->environment) !== $scopeId) {
            throw new \LogicException('Financial account mismatch.');
        }
        $result = new PaymentFinancialResolution($p->id, $p->canonical_account_key, $p->environment, $p->prepared_scope['currency'], 'scope_conflict');
        try {
            $scope = $this->scope->snapshot($p->id, $scopeId);
        } catch (ValidationException) {
            return $result;
        }
        $result->entity_key = $scope['entity_key'];
        $request = PaymentFinancialReadRequest::where('payment_association_scope_id', $scopeId)->where('entity_key', $scope['entity_key'])->orderByDesc('id')->lockForUpdate()->first();
        if ($request === null) {
            $result->status = 'missing';

            return $result;
        }
        $result->request_id = $request->id;
        $result->request_started_at = $request->started_at;
        $observation = PaymentFinancialObservation::where('payment_financial_read_request_id', $request->id)->lockForUpdate()->first();
        if ($observation === null) {
            $result->status = 'pending';

            return $result;
        }
        $result->observation_id = $observation->id;
        $result->reported_at = $observation->recorded_at;
        if ($request->started_at->greaterThan(CarbonImmutable::now()) || $request->started_at->lessThan(CarbonImmutable::now()->subSeconds(self::FRESH_SECONDS))) {
            $result->status = 'stale';

            return $result;
        }
        try {
            $source = $this->scope->snapshot($request->payment_dispatch_preparation_id, $scopeId);
        } catch (ValidationException) {
            return $result;
        }
        if ($source['entity_key'] !== $scope['entity_key'] || ! hash_equals($request->scope_fingerprint, $source['fingerprint'])) {
            return $result;
        }
        $result->status = $observation->status;
        if ($result->status !== 'qualified_reported') {
            return $result;
        }
        $history = PaymentFinancialObservation::join('payment_financial_read_requests as requests', 'requests.id', '=', 'payment_financial_observations.payment_financial_read_request_id')
            ->where('requests.payment_association_scope_id', $scopeId)->where('requests.entity_key', $scope['entity_key'])->where('requests.id', '<=', $request->id)
            ->orderBy('requests.id')->limit(257)->lockForUpdate()->get(['payment_financial_observations.*']);
        if ($history->count() > 256) {
            $result->status = 'history_limit_quarantined';

            return $result;
        }
        foreach ($history as $prior) {
            if (in_array($prior->status, ['transition_conflict', 'reversal_unqualified'], true)) {
                $result->status = $prior->status;

                return $result;
            }
            if ($prior->status === 'qualified_reported' && in_array($prior->classification, ['settled', 'refunded', 'voided', 'expired'], true)
             && $prior->classification !== $observation->classification) {
                $result->status = 'transition_conflict';

                return $result;
            }
        }
        $result->classification = $observation->classification;
        $result->amounts = $observation->facts['amounts'];
        $result->qualified_reported_amounts = true;

        return $result;
    }
}
