<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentFinancialResolution;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentFinancialObservation;
use App\Models\Payments\PaymentFinancialReadRequest;
use App\Services\Payments\AuthorizeNetCurrencyAuthority;
use App\Services\Payments\ClassifyPaymentFinancialObservation;
use App\Services\Payments\PaymentAssociationLock;
use App\Services\Payments\PaymentFinancialEvidenceScope;
use App\Services\Payments\ReadAuthorizeNetTransaction;
use App\Services\Payments\ResolvePaymentFinancialEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Durable reservation precedes internally owned HTTP; no retries or operation/order mutations. */
class RecordPaymentFinancialObservationAction
{
    public function __construct(private readonly PaymentAssociationLock $lock, private readonly PaymentFinancialEvidenceScope $scope,
        private readonly ReadAuthorizeNetTransaction $reader, private readonly AuthorizeNetCurrencyAuthority $currency,
        private readonly ClassifyPaymentFinancialObservation $classifier, private readonly ResolvePaymentFinancialEvidence $resolver) {}

    public function execute(int $preparationId): PaymentFinancialResolution
    {
        if (DB::transactionLevel() !== 0) {
            throw ValidationException::withMessages(['payment' => 'Financial reporting requires its own transaction.']);
        }
        $p = PaymentDispatchPreparation::findOrFail($preparationId);
        [$request,$before] = DB::transaction(function () use ($p) {
            $scopeId = $this->lock->acquire($p->canonical_account_key, $p->environment);
            $before = $this->scope->snapshot($p->id, $scopeId);
            $request = PaymentFinancialReadRequest::create(['payment_association_scope_id' => $scopeId, 'payment_dispatch_preparation_id' => $p->id,
                'entity_key' => $before['entity_key'], 'scope_fingerprint' => $before['fingerprint'], 'started_at' => CarbonImmutable::now('UTC')]);

            return [$request, $before];
        }, 3);
        try {
            $read = $this->reader->execute($before['read']);
        } catch (Throwable) {
            $read = null;
        }

        return DB::transaction(function () use ($p, $request, $before, $read) {
            $scopeId = $this->lock->acquire($p->canonical_account_key, $p->environment);
            $status = $read === null ? 'read_failed' : 'scope_conflict';
            $classification = null;
            $facts = [];
            try {
                $after = $this->scope->snapshot($p->id, $scopeId);
            } catch (ValidationException) {
                $after = null;
            }
            if ($read !== null && $after !== null && hash_equals($request->scope_fingerprint, $after['fingerprint'])
             && $read->submitted_at !== null && $read->submitted_at->equalTo(CarbonImmutable::parse($before['entity']['submitted_at']))
             && $read->read_at->greaterThanOrEqualTo($request->started_at) && $read->read_at->lessThanOrEqualTo(CarbonImmutable::now())
             && $read->canonical_account_key === $p->canonical_account_key && $read->transaction_id === $before['entity']['transaction_id']) {
                $policy = $this->currency->assess($after['binding'], $read);
                $classified = $this->classifier->classify($after, $read, $policy);
                $status = $classified['status'];
                $classification = $classified['classification'];
                $facts = ['version' => 1, 'transaction_status' => $read->transaction_status, 'transaction_type' => $read->transaction_type,
                    'authorized_amount_minor' => $read->authorized_amount_minor, 'settlement_amount_minor' => $read->settlement_amount_minor,
                    'currency_policy' => $policy, 'payment_rail' => $read->payment_rail, 'original_transaction_id' => $read->original_transaction_id,
                    'submitted_at' => $read->submitted_at->format('Y-m-d\TH:i:s.u\Z'), 'reported_at' => $read->read_at->format('Y-m-d\TH:i:s.u\Z'),
                    'amounts' => $classified['amounts'], 'source' => 'authenticated_reporting_and_owned_evidence', 'bank_cash_verified' => false];
            }
            PaymentFinancialObservation::create(['payment_financial_read_request_id' => $request->id, 'status' => $status, 'classification' => $classification,
                'facts' => $facts, 'recorded_at' => CarbonImmutable::now('UTC')]);

            return $this->resolver->underLock($p, $scopeId);
        }, 3);
    }
}
