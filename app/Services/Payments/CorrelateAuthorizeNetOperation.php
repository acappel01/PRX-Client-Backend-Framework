<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayTransactionReadData;
use App\Data\Payments\PaymentReferenceCorrelation;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit read-only comparison; caller must authorize access. No inbox consumption or state update. */
class CorrelateAuthorizeNetOperation
{
    public function __construct(private readonly PaymentOperationReferenceScope $scope, private readonly ReadAuthorizeNetTransaction $reader) {}

    public function execute(int $referenceId, string $transactionId): PaymentReferenceCorrelation
    {
        if (DB::transactionLevel() !== 0) {
            $this->reject();
        }
        [$operation, $data] = $this->snapshot($referenceId, $transactionId);
        $read = $this->reader->execute($data);
        $this->snapshot($referenceId, $transactionId);

        return new PaymentReferenceCorrelation($operation->uuid, $read);
    }

    private function snapshot(int $referenceId, string $transactionId): array
    {
        return DB::transaction(function () use ($referenceId, $transactionId): array {
            $reference = PaymentOperationReference::findOrFail($referenceId);
            $operation = PaymentOperation::whereKey($reference->payment_operation_id)->lockForUpdate()->firstOrFail();
            $binding = GatewayAccountBinding::whereKey($reference->gateway_account_binding_id)->firstOrFail();
            $intent = $this->scope->assertCurrent($operation, $binding);
            if ($reference->canonical_account_key !== $binding->canonical_account_key || $reference->environment !== $binding->environment) {
                $this->reject();
            }
            // Capture/void actor identity and refund original-operation mapping need separate contracts.
            $type = match ($operation->purpose) {
                PaymentOperationPurpose::Sale => 'authCaptureTransaction',
                PaymentOperationPurpose::Authorize => 'authOnlyTransaction',
                default => null,
            };
            if ($type === null || $operation->original_operation_id !== null) {
                $this->reject();
            }

            return [$operation, new GatewayTransactionReadData($binding->id, $transactionId, $type, null,
                $operation->amount_minor, $intent->currency, expected_merchant_reference: $reference->reference)];
        }, 3);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['payment' => 'This operation cannot be correlated under the supported reference contract.']);
    }
}
