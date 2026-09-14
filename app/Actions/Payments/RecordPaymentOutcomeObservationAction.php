<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentOutcomeObservationData;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOutcomeObservation;
use App\Services\Payments\PaymentLedgerScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Retains follow-up claims after drift; never reconciles or executes money. */
class RecordPaymentOutcomeObservationAction
{
    public function __construct(private readonly PaymentLedgerScope $scope) {}

    public function execute(PaymentOutcomeObservationData $data): PaymentOutcomeObservation
    {
        $input = $data->toArray();
        foreach (['uuid', 'operation_uuid', 'merchant_account_uuid'] as $field) {
            $input[$field] = strtolower($input[$field]);
        }
        $input['observed_at'] = $data->observed_at->utc()->toISOString();
        $referenceRules = ['nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/'];
        Validator::make($input, [
            'uuid' => ['required', 'uuid'], 'operation_uuid' => ['required', 'uuid'],
            'merchant_account_uuid' => ['required', 'uuid'],
            'source_key' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_.:-]*\z/'],
            'gateway_transaction_reference' => $referenceRules,
            'original_gateway_transaction_reference' => $referenceRules,
            'source_event_reference' => $referenceRules,
            'reported_amount_minor' => ['nullable', 'required_with:reported_currency', 'integer', 'min:0', 'max:999999999999'],
            'reported_currency' => ['nullable', 'required_with:reported_amount_minor', 'string', 'regex:/\A[A-Z]{3}\z/'],
        ])->validate();
        $fingerprint = $this->scope->fingerprint($input);

        return DB::transaction(function () use ($input, $data, $fingerprint): PaymentOutcomeObservation {
            $existing = PaymentOutcomeObservation::query()->where('uuid', $input['uuid'])->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->replay($existing, $fingerprint);
            }
            $operation = PaymentOperation::query()->where('uuid', $input['operation_uuid'])->firstOrFail();
            $intent = PaymentIntent::query()->whereKey($operation->payment_intent_id)->lockForUpdate()->firstOrFail();
            if ($intent->merchant_account_uuid !== $input['merchant_account_uuid']
                || $intent->gateway_provider !== $data->gateway_provider || $intent->environment !== $data->environment) {
                throw ValidationException::withMessages(['payment' => 'The reported account scope does not match the frozen payment intent.']);
            }
            try {
                return DB::transaction(fn () => PaymentOutcomeObservation::create([
                    'uuid' => $input['uuid'], 'payment_operation_id' => $operation->id,
                    'request_fingerprint' => $fingerprint, 'reported_evidence' => $input,
                    'created_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $existing = PaymentOutcomeObservation::query()->where('uuid', $input['uuid'])->lockForUpdate()->first();
                if ($existing === null) {
                    throw $exception;
                }

                return $this->replay($existing, $fingerprint);
            }
        }, 3);
    }

    private function replay(PaymentOutcomeObservation $observation, string $fingerprint): PaymentOutcomeObservation
    {
        if (! hash_equals($observation->request_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['payment' => 'This observation identity already describes different evidence.']);
        }

        return $observation;
    }
}
