<?php

namespace App\Services\Payments;

use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use Carbon\CarbonImmutable;

/** A reporting association alone cannot establish that the local parent executor acted. */
class PaymentParentDispatchEvidence
{
    public function __construct(private readonly PaymentLedgerScope $ledger, private readonly PaymentOperationLineageScope $scope) {}

    public function assertCurrent(PaymentDispatchPreparation $preparation, array $entity): PaymentDispatchAttempt
    {
        [$current, $operation, $binding] = $this->scope->current($preparation->id);
        $attempt = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $current->id)->lockForUpdate()->first();
        if ($attempt === null || $attempt->status !== 'response_observed' || $attempt->payment_operation_id !== $operation->id
            || $attempt->transport_started_at === null || $attempt->completed_at === null
            || $attempt->claimed_at->lessThan($current->prepared_at) || $attempt->transport_started_at->lessThan($attempt->claimed_at)
            || $attempt->completed_at->lessThan($attempt->transport_started_at)) {
            $this->scope->reject();
        }
        $expectedOriginal = match ($operation->purpose->value) {
            'sale', 'authorize' => null,
            'capture', 'void' => $entity['transaction_id'],
            'refund' => $entity['original_transaction_id'],
        };
        $expected = ['preparation_uuid' => $current->uuid, 'operation_uuid' => $operation->uuid,
            'gateway_account_binding_id' => $binding->id, 'canonical_account_key' => $binding->canonical_account_key,
            'environment' => $binding->environment, 'merchant_reference' => $current->prepared_scope['merchant_reference'],
            'purpose' => $operation->purpose->value, 'amount_minor' => $operation->amount_minor,
            'currency' => $binding->currency, 'original_transaction_id' => $expectedOriginal,
            'merchant_binding_fingerprint' => $binding->merchant_fingerprint];
        $request = $attempt->request_facts;
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $request) || $request[$key] !== $value) {
                $this->scope->reject();
            }
        }
        $receipt = $attempt->receipt;
        if (! hash_equals($attempt->request_fingerprint, $this->ledger->fingerprint($request))
            || ($receipt['request_fingerprint'] ?? null) !== $attempt->request_fingerprint
            || ($receipt['response_code'] ?? null) !== '1' || ($receipt['transaction_id'] ?? null) !== $entity['transaction_id']
            || ($receipt['echoed_ref_id'] ?? null) !== $expected['merchant_reference']
            || (($receipt['response_original_id'] ?? null) !== null && $receipt['response_original_id'] !== $expectedOriginal)) {
            $this->scope->reject();
        }
        if (in_array($operation->purpose->value, ['sale', 'authorize', 'refund'], true)) {
            $submitted = CarbonImmutable::parse($entity['submitted_at']);
            if ($submitted->lessThanOrEqualTo($current->prepared_at) || $submitted->lessThan($attempt->transport_started_at)
                || $submitted->greaterThan($attempt->completed_at)) {
                $this->scope->reject();
            }
        }

        return $attempt;
    }
}
