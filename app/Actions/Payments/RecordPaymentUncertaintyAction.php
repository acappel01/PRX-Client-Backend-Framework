<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentUncertaintyData;
use App\Enums\Payments\PaymentOperationState;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Services\Payments\PaymentLedgerScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Retain uncertainty, even after configuration drift; never call a gateway to retry. */
class RecordPaymentUncertaintyAction
{
    public function __construct(private readonly PaymentLedgerScope $scope) {}

    public function execute(PaymentUncertaintyData $data): PaymentOperation
    {
        $evidence = [
            'reason' => $data->reason->value,
            'observed_at' => $data->observed_at->utc()->toISOString(),
            'gateway_transaction_reference' => $data->gateway_transaction_reference,
            'original_gateway_transaction_reference' => $data->original_gateway_transaction_reference,
        ];
        Validator::make(['uuid' => $data->operation_uuid] + $evidence, [
            'uuid' => ['required', 'uuid'],
            'gateway_transaction_reference' => ['nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/'],
            'original_gateway_transaction_reference' => ['nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/'],
        ])->validate();
        $fingerprint = $this->scope->fingerprint($evidence);

        return DB::transaction(function () use ($data, $evidence, $fingerprint): PaymentOperation {
            $identity = PaymentOperation::query()->where('uuid', strtolower($data->operation_uuid))->firstOrFail();
            PaymentIntent::query()->whereKey($identity->payment_intent_id)->lockForUpdate()->firstOrFail();
            $operation = PaymentOperation::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();
            if ($operation->state === PaymentOperationState::Uncertain) {
                if (! hash_equals($operation->uncertainty_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['payment' => 'The original uncertainty evidence cannot be replaced.']);
                }

                return $operation;
            }
            $operation->forceFill([
                'state' => PaymentOperationState::Uncertain,
                'uncertain_at' => now(),
                'uncertainty_fingerprint' => $fingerprint,
                'uncertainty_evidence' => $evidence,
            ])->save();

            return $operation;
        }, 3);
    }
}
