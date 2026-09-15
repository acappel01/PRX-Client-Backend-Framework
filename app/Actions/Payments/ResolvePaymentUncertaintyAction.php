<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentUncertaintyResolutionResult;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentUncertaintyResolution;
use App\Services\Payments\PaymentAssociationLock;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\ResolvePaymentUncertaintyEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Local durable evidence resolution only. Never sends/retries or changes operation/claim history. */
class ResolvePaymentUncertaintyAction
{
    public function __construct(private readonly PaymentAssociationLock $lock, private readonly ResolvePaymentUncertaintyEvidence $resolver,
        private readonly PaymentLedgerScope $ledger) {}

    public function execute(string $operationUuid): PaymentUncertaintyResolutionResult
    {
        if (DB::transactionLevel() !== 0) {
            $this->resolver->reject();
        }
        $operation = PaymentOperation::where('uuid', strtolower($operationUuid))->firstOrFail();
        $p = PaymentDispatchPreparation::where('payment_operation_id', $operation->id)->first();
        if ($p === null) {
            $this->resolver->reject();
        }

        return DB::transaction(function () use ($operation, $p) {
            $sid = $this->lock->acquire($p->canonical_account_key, $p->environment);
            $existing = $this->resolver->underLock($operation, $sid);
            if ($existing->resolution_id !== null) {
                return $existing;
            }
            [$current,$scope,$read] = $this->resolver->qualifyUnderLock($operation, $sid);
            $evidence = ['version' => 1, 'uncertainty_fingerprint' => $current->uncertainty_fingerprint,
                'uncertain_at' => $current->uncertain_at->format('Y-m-d\TH:i:s\Z'), 'uncertainty_timestamp_precision' => 'second',
                'canonical_account_key' => $read->canonical_account_key, 'environment' => $read->environment, 'entity_key' => $read->entity_key, 'currency' => $read->currency,
                'classification' => $read->classification, 'reported_amounts' => $read->amounts,
                'request_started_at' => $read->request_started_at->format('Y-m-d\TH:i:s.u\Z'),
                'source' => 'owned_receipt_and_fresh_authenticated_financial_reporting', 'execution_released' => false, 'bank_cash_verified' => false];
            PaymentUncertaintyResolution::create(['payment_operation_id' => $current->id, 'payment_dispatch_attempt_id' => $scope['attempt']->id,
                'payment_financial_read_request_id' => $read->request_id, 'payment_financial_observation_id' => $read->observation_id,
                'uncertainty_fingerprint' => $current->uncertainty_fingerprint, 'evidence_fingerprint' => $this->ledger->fingerprint($evidence),
                'evidence' => $evidence, 'resolved_at' => CarbonImmutable::now('UTC')]);

            return $this->resolver->underLock($current, $sid);
        }, 3);
    }
}
