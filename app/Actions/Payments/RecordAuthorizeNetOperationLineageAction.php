<?php

namespace App\Actions\Payments;

use App\Data\Payments\GatewayTransactionReadData;
use App\Data\Payments\PaymentOperationLineageResolution;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentOperationEffectAssociation;
use App\Services\Payments\AuthorizeNetCurrencyAuthority;
use App\Services\Payments\PaymentAssociationLock;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationLineageScope;
use App\Services\Payments\PaymentParentDispatchEvidence;
use App\Services\Payments\ReadAuthorizeNetTransaction;
use App\Services\Payments\ResolvePaymentOperationLineage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Correlates dispatch-owned actor receipts with authenticated entity reads; no financial effects. */
class RecordAuthorizeNetOperationLineageAction
{
    public function __construct(private readonly PaymentAssociationLock $lock, private readonly PaymentOperationLineageScope $scope,
        private readonly PaymentLedgerScope $ledger, private readonly ResolvePaymentOperationLineage $resolver,
        private readonly ReadAuthorizeNetTransaction $reader, private readonly AuthorizeNetCurrencyAuthority $currency,
        private readonly PaymentParentDispatchEvidence $parentProof) {}

    public function execute(int $preparationId, int $dispatchAttemptId): PaymentOperationLineageResolution
    {
        if (DB::transactionLevel() !== 0) {
            $this->scope->reject();
        }
        $identity = PaymentDispatchPreparation::findOrFail($preparationId);
        $before = DB::transaction(function () use ($identity, $dispatchAttemptId): array {
            $scopeId = $this->lock->acquire($identity->canonical_account_key, $identity->environment);

            return $this->snapshot($identity->id, $dispatchAttemptId, $scopeId);
        }, 3);
        // Every remote read, including refund's original settled entity, is outside DB transactions.
        $parentRead = $before['parent_read'] === null ? null : $this->reader->execute($before['parent_read']);
        $read = $this->reader->execute($before['read']);

        return DB::transaction(function () use ($identity, $dispatchAttemptId, $before, $parentRead, $read): PaymentOperationLineageResolution {
            $scopeId = $this->lock->acquire($identity->canonical_account_key, $identity->environment);
            $after = $this->snapshot($identity->id, $dispatchAttemptId, $scopeId);
            $preparation = $after['preparation'];
            $attempt = $after['attempt'];
            $purpose = $after['operation']->purpose;
            if (! hash_equals($before['fingerprint'], $after['fingerprint']) || $read->submitted_at === null
                || $read->read_at->lessThan($attempt->completed_at) || $read->submitted_at->greaterThan($read->read_at)
                || $read->canonical_account_key !== $preparation->canonical_account_key
                || $read->transaction_id !== $after['read']->transaction_id) {
                $this->scope->reject();
            }
            $parentEntity = $after['parent_result']['entity'];
            if ($purpose === PaymentOperationPurpose::Refund) {
                if ($parentRead === null || $parentRead->transaction_status !== 'settledSuccessfully'
                    || $parentRead->settlement_amount_minor < $after['operation']->amount_minor
                    || $read->submitted_at->lessThanOrEqualTo($preparation->prepared_at)
                    || $read->submitted_at->lessThan($attempt->transport_started_at)
                    || $read->submitted_at->greaterThan($attempt->completed_at)
                    || ! in_array($read->transaction_status, ['refundPendingSettlement', 'refundSettledSuccessfully'], true)) {
                    $this->scope->reject();
                }
            } else {
                // Capture and void operate on the original entity; its submission predates this effect.
                if (! $read->submitted_at->equalTo(CarbonImmutable::parse($parentEntity['submitted_at']))
                    || ($purpose === PaymentOperationPurpose::Capture && (! in_array($read->transaction_status, ['capturedPendingSettlement', 'settledSuccessfully'], true)
                        || $read->authorized_amount_minor !== $parentEntity['authorized_amount_minor']))
                    || ($purpose === PaymentOperationPurpose::Void && $read->transaction_status !== 'voided')) {
                    $this->scope->reject();
                }
            }
            $policy = $this->currency->assess($after['binding'], $read);
            $parentPolicy = $parentRead === null ? null : $this->currency->assess($after['binding'], $parentRead);
            $entity = ['transaction_id' => $read->transaction_id, 'transaction_type' => $read->transaction_type,
                'transaction_status' => $read->transaction_status, 'original_transaction_id' => $read->original_transaction_id,
                'merchant_reference' => $read->merchant_reference, 'authorized_amount_minor' => $read->authorized_amount_minor,
                'settlement_amount_minor' => $read->settlement_amount_minor, 'currency_policy' => $policy,
                'submitted_at' => $read->submitted_at->format('Y-m-d\TH:i:s.u\Z'),
                'read_at' => $read->read_at->format('Y-m-d\TH:i:s.u\Z'), 'payment_rail' => $read->payment_rail];
            $facts = ['version' => 1, 'effect_kind' => $purpose->value, 'entity' => $entity,
                'parent_transaction_id' => $parentEntity['transaction_id'], 'parent_preparation_id' => $after['parent_preparation']->id,
                'parent_evidence_fingerprint' => $after['parent_result']['fingerprint'],
                'parent_read_currency_policy' => $parentPolicy,
                'dispatch_request_fingerprint' => $attempt->request_fingerprint,
                'dispatch_claimed_at' => $attempt->claimed_at->format('Y-m-d\TH:i:s.u\Z'),
                'transport_started_at' => $attempt->transport_started_at->format('Y-m-d\TH:i:s.u\Z'),
                'response_observed_at' => $attempt->completed_at->format('Y-m-d\TH:i:s.u\Z'),
                'response_code' => $attempt->receipt['response_code'], 'provenance' => 'dispatch_owned_response_and_reporting',
                'actor_correlated' => true, 'operation_verified' => false, 'financial_effects_verified' => false];
            $candidate = new PaymentOperationEffectAssociation([
                'payment_association_scope_id' => $scopeId, 'payment_dispatch_preparation_id' => $preparation->id,
                'parent_preparation_id' => $after['parent_preparation']->id, 'payment_operation_id' => $after['operation']->id,
                'payment_dispatch_attempt_id' => $attempt->id, 'effect_kind' => $purpose->value,
                'transaction_key' => $this->ledger->fingerprint(['association_transaction_v1' => $read->transaction_id]),
                'parent_transaction_key' => $this->ledger->fingerprint(['association_transaction_v1' => $parentEntity['transaction_id']]),
                'evidence_fingerprint' => $this->ledger->fingerprint($facts), 'amount_minor' => $after['operation']->amount_minor,
                'currency_qualified' => $policy['currency_qualified'] && ($parentPolicy === null || $parentPolicy['currency_qualified']),
                'facts' => $facts, 'recorded_at' => CarbonImmutable::now('UTC'),
            ]);
            PaymentOperationEffectAssociation::query()->insertOrIgnore($candidate->getAttributes());
            PaymentOperationEffectAssociation::where('payment_dispatch_preparation_id', $preparation->id)
                ->where('evidence_fingerprint', $candidate->evidence_fingerprint)->lockForUpdate()->sole();

            return $this->resolver->result($preparation->id, $this->resolver->underLock($preparation, $scopeId));
        }, 3);
    }

    private function snapshot(int $preparationId, int $attemptId, int $scopeId): array
    {
        [$preparation, $operation, $binding, $parent, $scopeFingerprint] = $this->scope->current($preparationId);
        if ($parent === null || ! in_array($operation->purpose, [PaymentOperationPurpose::Capture, PaymentOperationPurpose::Refund, PaymentOperationPurpose::Void], true)) {
            $this->scope->reject();
        }
        $parentPreparation = PaymentDispatchPreparation::where('payment_operation_id', $parent->id)->lockForUpdate()->first();
        if ($parentPreparation === null || $parentPreparation->canonical_account_key !== $preparation->canonical_account_key
            || $parentPreparation->environment !== $preparation->environment) {
            $this->scope->reject();
        }
        $this->scope->current($parentPreparation->id);
        $parentResult = $this->resolver->underLock($parentPreparation, $scopeId);
        if (! in_array($parentResult['status'], ['associated_only', 'effect_correlated_only'], true)) {
            $this->scope->reject();
        }
        $this->parentProof->assertCurrent($parentPreparation, $parentResult['entity']);
        $attempt = PaymentDispatchAttempt::whereKey($attemptId)->lockForUpdate()->first();
        if ($attempt === null || $attempt->payment_dispatch_preparation_id !== $preparation->id
            || $attempt->payment_operation_id !== $operation->id || $attempt->status !== 'response_observed'
            || $attempt->transport_started_at === null || $attempt->completed_at === null
            || $attempt->claimed_at->lessThan($preparation->prepared_at)
            || $attempt->transport_started_at->lessThan($attempt->claimed_at) || $attempt->completed_at->lessThan($attempt->transport_started_at)) {
            $this->scope->reject();
        }
        $request = $attempt->request_facts;
        $receipt = $attempt->receipt;
        $entity = $parentResult['entity'];
        $expected = ['preparation_uuid' => $preparation->uuid, 'operation_uuid' => $operation->uuid,
            'gateway_account_binding_id' => $binding->id, 'canonical_account_key' => $binding->canonical_account_key,
            'environment' => $binding->environment, 'merchant_reference' => $preparation->prepared_scope['merchant_reference'],
            'purpose' => $operation->purpose->value, 'amount_minor' => $operation->amount_minor, 'currency' => $binding->currency,
            'original_transaction_id' => $entity['transaction_id'], 'parent_preparation_id' => $parentPreparation->id,
            'merchant_binding_fingerprint' => $binding->merchant_fingerprint];
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $request) || $request[$key] !== $value) {
                $this->scope->reject();
            }
        }
        if (! hash_equals($attempt->request_fingerprint, $this->ledger->fingerprint($request))
            || ($receipt['request_fingerprint'] ?? null) !== $attempt->request_fingerprint
            || ($receipt['response_code'] ?? null) !== '1' || ($receipt['echoed_ref_id'] ?? null) !== $expected['merchant_reference']
            || ! is_string($receipt['transaction_id'] ?? null) || ! preg_match('/\A[1-9][0-9]{0,31}\z/', $receipt['transaction_id'])
            || (($receipt['response_original_id'] ?? null) !== null && $receipt['response_original_id'] !== $entity['transaction_id'])) {
            $this->scope->reject();
        }
        $isRefund = $operation->purpose === PaymentOperationPurpose::Refund;
        if (($receipt['transaction_id'] === $entity['transaction_id']) === $isRefund
            || ($isRefund && $operation->amount_minor > $entity['settlement_amount_minor'])) {
            $this->scope->reject();
        }
        $type = $isRefund ? 'refundTransaction' : $entity['transaction_type'];
        $basis = $operation->purpose === PaymentOperationPurpose::Void ? 'authorization' : 'settlement';
        $amount = $operation->purpose === PaymentOperationPurpose::Void ? $entity['authorized_amount_minor'] : $operation->amount_minor;
        $read = new GatewayTransactionReadData($binding->id, $receipt['transaction_id'], $type,
            $isRefund ? $entity['transaction_id'] : $entity['original_transaction_id'], $amount, $binding->currency,
            expected_merchant_reference: $isRefund ? $expected['merchant_reference'] : null, expected_amount_basis: $basis);
        $parentRead = $isRefund ? new GatewayTransactionReadData($binding->id, $entity['transaction_id'], $entity['transaction_type'],
            $entity['original_transaction_id'], $entity['settlement_amount_minor'], $binding->currency, expected_amount_basis: 'settlement') : null;

        return ['preparation' => $preparation, 'operation' => $operation, 'binding' => $binding, 'attempt' => $attempt,
            'parent_preparation' => $parentPreparation, 'parent_result' => $parentResult, 'read' => $read, 'parent_read' => $parentRead,
            'fingerprint' => $this->ledger->fingerprint([$scopeFingerprint, $parentResult['fingerprint'], $attempt->request_fingerprint, $receipt])];
    }
}
