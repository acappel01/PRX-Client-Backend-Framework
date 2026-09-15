<?php

namespace App\Services\Payments;

use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal caller first holds the canonical account mutex; scope then locks intent before operations. */
class PaymentOperationLineageScope
{
    public function __construct(private readonly PaymentOperationReferenceScope $scope, private readonly PaymentLedgerScope $ledger) {}

    public function current(int $preparationId): array
    {
        if (DB::transactionLevel() === 0) {
            $this->reject();
        }
        $identity = PaymentDispatchPreparation::findOrFail($preparationId);
        $opIdentity = PaymentOperation::findOrFail($identity->payment_operation_id);
        $intent = PaymentIntent::whereKey($opIdentity->payment_intent_id)->lockForUpdate()->firstOrFail();
        $operation = PaymentOperation::whereKey($opIdentity->id)->lockForUpdate()->firstOrFail();
        $reference = PaymentOperationReference::whereKey($identity->payment_operation_reference_id)->lockForUpdate()->firstOrFail();
        $preparation = PaymentDispatchPreparation::whereKey($identity->id)->lockForUpdate()->firstOrFail();
        $binding = GatewayAccountBinding::findOrFail($preparation->gateway_account_binding_id);
        $this->scope->assertCurrent($operation, $binding);
        $frozen = $preparation->prepared_scope;
        $expected = ['operation_id' => $operation->id, 'operation_uuid' => $operation->uuid,
            'intent_id' => $intent->id, 'intent_uuid' => $intent->uuid,
            'gateway_account_binding_id' => $binding->id, 'canonical_account_key' => $binding->canonical_account_key,
            'environment' => $binding->environment, 'merchant_binding_fingerprint' => $intent->merchant_binding_fingerprint,
            'order_snapshot_fingerprint' => $intent->order_snapshot_fingerprint,
            'operation_request_fingerprint' => $operation->request_fingerprint,
            'payment_operation_reference_id' => $reference->id, 'merchant_reference' => $reference->reference,
            'purpose' => $operation->purpose->value, 'amount_minor' => $operation->amount_minor,
            'currency' => $intent->currency, 'executor_key' => $operation->executor_key,
            'original_operation_id' => $operation->original_operation_id];
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $frozen) || $frozen[$key] !== $value) {
                $this->reject();
            }
        }
        if ($preparation->state !== 'prepared_only' || $preparation->executor_key !== $operation->executor_key
            || $preparation->canonical_account_key !== $binding->canonical_account_key || $preparation->environment !== $binding->environment
            || $reference->payment_operation_id !== $operation->id || $reference->gateway_account_binding_id !== $binding->id
            || $reference->canonical_account_key !== $binding->canonical_account_key || $reference->environment !== $binding->environment) {
            $this->reject();
        }
        $parent = $operation->original_operation_id === null ? null : PaymentOperation::whereKey($operation->original_operation_id)->lockForUpdate()->first();
        $allowed = match ($operation->purpose) {
            PaymentOperationPurpose::Capture => [PaymentOperationPurpose::Authorize],
            PaymentOperationPurpose::Refund => [PaymentOperationPurpose::Sale, PaymentOperationPurpose::Capture],
            PaymentOperationPurpose::Void => [PaymentOperationPurpose::Sale, PaymentOperationPurpose::Authorize, PaymentOperationPurpose::Capture],
            default => [],
        };
        if (($allowed === [] && $parent !== null) || ($allowed !== [] && ($parent === null || $parent->payment_intent_id !== $intent->id
            || ! in_array($parent->purpose, $allowed, true) || $operation->amount_minor > $parent->amount_minor
            || ($operation->purpose === PaymentOperationPurpose::Void && $operation->amount_minor !== $parent->amount_minor)))) {
            $this->reject();
        }

        return [$preparation, $operation, $binding, $parent,
            $this->ledger->fingerprint($expected + ['current_state' => $operation->state->value, 'uncertainty' => $operation->uncertainty_fingerprint])];
    }

    public function reject(): never
    {
        throw ValidationException::withMessages(['payment' => 'Current operation lineage or authoritative effect evidence is unavailable.']);
    }
}
