<?php

namespace App\Actions\Payments;

use App\Enums\Payments\PaymentOperationState;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Services\Payments\PaymentOperationReferenceScope;
use App\Services\Payments\PaymentReferenceGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Trusted internal reservation; a returned reference must never trigger a payment. */
class ReservePaymentOperationReferenceAction
{
    public function __construct(private readonly PaymentOperationReferenceScope $scope, private readonly PaymentReferenceGenerator $generator) {}

    public function execute(string $operationUuid, int $bindingId): PaymentOperationReference
    {
        Validator::make(['uuid' => $operationUuid, 'binding' => $bindingId], ['uuid' => ['required', 'uuid'], 'binding' => ['integer', 'min:1']])->validate();

        return DB::transaction(function () use ($operationUuid, $bindingId): PaymentOperationReference {
            $operation = PaymentOperation::where('uuid', strtolower($operationUuid))->lockForUpdate()->firstOrFail();
            $existing = PaymentOperationReference::where('payment_operation_id', $operation->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->gateway_account_binding_id !== $bindingId) {
                    $this->reject();
                }

                // Historical replay returns identity even after uncertainty or drift. It authorizes nothing.
                return $existing;
            }
            $binding = GatewayAccountBinding::whereKey($bindingId)->firstOrFail();
            $intent = $this->scope->assertCurrent($operation, $binding);
            if ($operation->state !== PaymentOperationState::Prepared
                || PaymentOperation::where('payment_intent_id', $intent->id)->where('state', PaymentOperationState::Uncertain->value)->lockForUpdate()->exists()) {
                $this->reject();
            }
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $reference = $this->generator->generate();
                if (! preg_match('/\A[a-f0-9]{20}\z/', $reference)) {
                    $this->reject();
                }
                try {
                    return DB::transaction(fn () => PaymentOperationReference::create([
                        'payment_operation_id' => $operation->id, 'gateway_account_binding_id' => $binding->id,
                        'canonical_account_key' => $binding->canonical_account_key, 'environment' => $binding->environment,
                        'reference' => $reference, 'created_at' => now(),
                    ]));
                } catch (UniqueConstraintViolationException $exception) {
                    // The locked operation serializes same-operation reservations; only reference collisions retry.
                    if (! PaymentOperationReference::where('canonical_account_key', $binding->canonical_account_key)
                        ->where('reference', $reference)->lockForUpdate()->exists()) {
                        throw $exception;
                    }
                }
            }
            $this->reject();
        }, 3);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['payment' => 'Payment reference reservation conflicts or cannot be prepared.']);
    }
}
