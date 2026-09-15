<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentDispatchPreparationData;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Enums\Payments\PaymentOperationState;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationReferenceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Trusted internal caller only. A durable return is preparation evidence, never a dispatch lease. */
class PreparePaymentDispatchAction
{
    public function __construct(private readonly PaymentLedgerScope $ledger, private readonly PaymentOperationReferenceScope $scope) {}

    public function execute(PaymentDispatchPreparationData $data): PaymentDispatchPreparation
    {
        if (DB::transactionLevel() !== 0) {
            $this->reject();
        }
        $input = ['uuid' => strtolower($data->uuid), 'payment_operation_reference_id' => $data->payment_operation_reference_id, 'executor_key' => $data->executor_key];
        Validator::make($input, [
            'uuid' => ['required', 'uuid'], 'payment_operation_reference_id' => ['required', 'integer', 'min:1'],
            'executor_key' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_.:-]*\z/'],
        ])->validate();
        $fingerprint = $this->ledger->fingerprint($input);

        return DB::transaction(function () use ($input, $fingerprint): PaymentDispatchPreparation {
            // Identity reads select immutable parents; all mutable scope/state is subsequently locked current.
            $referenceIdentity = PaymentOperationReference::findOrFail($input['payment_operation_reference_id']);
            $operationIdentity = PaymentOperation::findOrFail($referenceIdentity->payment_operation_id);
            $intent = PaymentIntent::whereKey($operationIdentity->payment_intent_id)->lockForUpdate()->firstOrFail();
            $operation = PaymentOperation::whereKey($operationIdentity->id)->lockForUpdate()->firstOrFail();
            $reference = PaymentOperationReference::whereKey($referenceIdentity->id)->lockForUpdate()->firstOrFail();
            $existing = PaymentDispatchPreparation::where('payment_operation_reference_id', $reference->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->replay($existing, $fingerprint);
            }
            $binding = GatewayAccountBinding::findOrFail($reference->gateway_account_binding_id);
            $this->scope->assertCurrent($operation, $binding);
            if ($operation->executor_key !== $input['executor_key'] || $operation->state !== PaymentOperationState::Prepared
                || $reference->canonical_account_key !== $binding->canonical_account_key || $reference->environment !== $binding->environment
                || ! preg_match('/\A[a-f0-9]{20}\z/', $reference->reference)
                || PaymentOperation::where('payment_intent_id', $intent->id)->where('state', PaymentOperationState::Uncertain->value)->lockForUpdate()->exists()) {
                $this->reject();
            }
            $original = $operation->original_operation_id === null ? null : PaymentOperation::whereKey($operation->original_operation_id)->lockForUpdate()->first();
            $allowedOriginals = match ($operation->purpose) {
                PaymentOperationPurpose::Sale, PaymentOperationPurpose::Authorize => [],
                PaymentOperationPurpose::Capture => [PaymentOperationPurpose::Authorize],
                PaymentOperationPurpose::Refund => [PaymentOperationPurpose::Sale, PaymentOperationPurpose::Capture],
                PaymentOperationPurpose::Void => [PaymentOperationPurpose::Sale, PaymentOperationPurpose::Authorize, PaymentOperationPurpose::Capture],
            };
            if (($allowedOriginals === [] && $original !== null)
                || ($allowedOriginals !== [] && ($original === null || $original->payment_intent_id !== $intent->id || ! in_array($original->purpose, $allowedOriginals, true)))
                || $operation->amount_minor < 1 || $operation->amount_minor > $intent->amount_minor
                || ($original !== null && $operation->amount_minor > $original->amount_minor)) {
                $this->reject();
            }
            $originalReference = $original === null ? null : PaymentOperationReference::where('payment_operation_id', $original->id)->lockForUpdate()->first();
            if ($originalReference !== null && ($originalReference->gateway_account_binding_id !== $binding->id
                || $originalReference->canonical_account_key !== $binding->canonical_account_key)) {
                $this->reject();
            }
            $snapshot = [
                'version' => 1, 'operation_id' => $operation->id, 'operation_uuid' => $operation->uuid,
                'intent_id' => $intent->id, 'intent_uuid' => $intent->uuid,
                'order_uuid' => $intent->order_uuid, 'customer_uuid' => $intent->customer_uuid,
                'merchant_account_id' => $intent->merchant_account_id, 'merchant_account_uuid' => $intent->merchant_account_uuid,
                'gateway_account_binding_id' => $binding->id, 'canonical_account_key' => $binding->canonical_account_key,
                'gateway_account_id' => $binding->gateway_account_id, 'gateway_provider' => $binding->gateway_provider,
                'environment' => $binding->environment, 'provider_mapping' => $binding->provider_mapping,
                'merchant_binding_fingerprint' => $intent->merchant_binding_fingerprint,
                'order_snapshot_fingerprint' => $intent->order_snapshot_fingerprint,
                'operation_request_fingerprint' => $operation->request_fingerprint,
                'payment_operation_reference_id' => $reference->id, 'merchant_reference' => $reference->reference,
                'purpose' => $operation->purpose->value, 'amount_minor' => $operation->amount_minor,
                'currency' => $intent->currency, 'executor_key' => $operation->executor_key,
                'original_operation_id' => $original?->id, 'original_operation_uuid' => $original?->uuid,
                'original_purpose' => $original?->purpose->value, 'original_amount_minor' => $original?->amount_minor,
                'original_reference_id' => $originalReference?->id, 'original_merchant_reference' => $originalReference?->reference,
            ];
            try {
                return DB::transaction(fn () => PaymentDispatchPreparation::create($input + [
                    'payment_operation_id' => $operation->id, 'gateway_account_binding_id' => $binding->id,
                    'canonical_account_key' => $binding->canonical_account_key, 'environment' => $binding->environment,
                    'state' => 'prepared_only', 'request_fingerprint' => $fingerprint, 'prepared_scope' => $snapshot,
                    'prepared_at' => CarbonImmutable::now('UTC'),
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $existing = PaymentDispatchPreparation::where('uuid', $input['uuid'])->lockForUpdate()->first();
                if ($existing === null) {
                    throw $exception;
                }

                return $this->replay($existing, $fingerprint);
            }
        }, 3);
    }

    private function replay(PaymentDispatchPreparation $preparation, string $fingerprint): PaymentDispatchPreparation
    {
        if (! hash_equals($preparation->request_fingerprint, $fingerprint)) {
            $this->reject();
        }

        return $preparation;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['payment' => 'Dispatch preparation conflicts or its frozen scope is unavailable.']);
    }
}
