<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentOperationData;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Enums\Payments\PaymentOperationState;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Services\Payments\PaymentLedgerScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Preparing an operation is neither permission to execute nor a financial result. */
class PreparePaymentOperationAction
{
    public function __construct(private readonly PaymentLedgerScope $scope) {}

    public function execute(PaymentOperationData $data): PaymentOperation
    {
        $input = $data->toArray();
        $input['uuid'] = strtolower($data->uuid);
        $input['intent_uuid'] = strtolower($data->intent_uuid);
        $input['original_operation_uuid'] = $data->original_operation_uuid === null ? null : strtolower($data->original_operation_uuid);
        Validator::make($input, [
            'uuid' => ['required', 'uuid'], 'intent_uuid' => ['required', 'uuid'],
            'original_operation_uuid' => ['nullable', 'uuid', 'different:uuid'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'executor_key' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_.:-]*\z/'],
        ])->validate();
        $fingerprint = $this->scope->fingerprint($input);

        return DB::transaction(function () use ($input, $fingerprint, $data): PaymentOperation {
            $existing = PaymentOperation::query()->where('uuid', $input['uuid'])->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->replay($existing, $fingerprint);
            }
            $intent = PaymentIntent::query()->where('uuid', $input['intent_uuid'])->lockForUpdate()->firstOrFail();
            $this->scope->assertCurrent($intent);
            if (PaymentOperation::query()->where('payment_intent_id', $intent->id)
                ->where('state', PaymentOperationState::Uncertain->value)->lockForUpdate()->first(['id']) !== null) {
                throw ValidationException::withMessages(['payment' => 'An uncertain operation requires reconciliation before preparing another operation.']);
            }
            $original = $input['original_operation_uuid'] === null ? null : PaymentOperation::query()
                ->where('uuid', $input['original_operation_uuid'])->lockForUpdate()->first();
            $allowedOriginals = match ($data->purpose) {
                PaymentOperationPurpose::Sale, PaymentOperationPurpose::Authorize => [],
                PaymentOperationPurpose::Capture => [PaymentOperationPurpose::Authorize],
                PaymentOperationPurpose::Refund => [PaymentOperationPurpose::Sale, PaymentOperationPurpose::Capture],
                PaymentOperationPurpose::Void => [PaymentOperationPurpose::Sale, PaymentOperationPurpose::Authorize, PaymentOperationPurpose::Capture],
            };
            if (($allowedOriginals === [] && $input['original_operation_uuid'] !== null)
                || ($allowedOriginals !== [] && ($original === null || $original->payment_intent_id !== $intent->id || ! in_array($original->purpose, $allowedOriginals, true)))
                || $data->amount_minor > ($original?->amount_minor ?? $intent->amount_minor)) {
                throw ValidationException::withMessages(['payment' => 'Operation amount or original operation lineage does not match this intent.']);
            }
            try {
                return DB::transaction(fn () => PaymentOperation::create([
                    'uuid' => $input['uuid'], 'payment_intent_id' => $intent->id,
                    'original_operation_id' => $original?->id,
                    'purpose' => $data->purpose, 'state' => PaymentOperationState::Prepared,
                    'amount_minor' => $data->amount_minor, 'executor_key' => $data->executor_key,
                    'request_fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $existing = PaymentOperation::query()->where('uuid', $input['uuid'])->lockForUpdate()->first();
                if ($existing === null) {
                    throw $exception;
                }

                return $this->replay($existing, $fingerprint);
            }
        }, 3);
    }

    private function replay(PaymentOperation $operation, string $fingerprint): PaymentOperation
    {
        if (! hash_equals($operation->request_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['payment' => 'This payment operation identity already describes a different request.']);
        }

        return $operation;
    }
}
