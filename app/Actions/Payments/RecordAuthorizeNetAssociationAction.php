<?php

namespace App\Actions\Payments;

use App\Data\Payments\GatewayTransactionReadData;
use App\Data\Payments\PaymentAssociationResolution;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Models\Payments\PaymentTransactionAssociation;
use App\Services\Payments\AuthorizeNetCurrencyAuthority;
use App\Services\Payments\PaymentAssociationLock;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationReferenceScope;
use App\Services\Payments\ReadAuthorizeNetTransaction;
use App\Services\Payments\ResolvePaymentTransactionAssociation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Trusted internal reporting orchestration only. Never accepts caller-authored provider evidence. */
class RecordAuthorizeNetAssociationAction
{
    public function __construct(
        private readonly PaymentOperationReferenceScope $scope,
        private readonly PaymentAssociationLock $lock,
        private readonly PaymentLedgerScope $ledger,
        private readonly ReadAuthorizeNetTransaction $reader,
        private readonly AuthorizeNetCurrencyAuthority $currency,
        private readonly ResolvePaymentTransactionAssociation $resolver,
    ) {}

    public function execute(int $preparationId, string $transactionId): PaymentAssociationResolution
    {
        if (DB::transactionLevel() !== 0) {
            $this->reject();
        }
        $identity = PaymentDispatchPreparation::findOrFail($preparationId);
        [$data, $before] = DB::transaction(function () use ($identity, $transactionId): array {
            $this->lock->acquire($identity->canonical_account_key, $identity->environment);

            return $this->snapshot($identity->id, $transactionId);
        }, 3);
        // The complete authoritative account/transaction read occurs outside all database transactions.
        $read = $this->reader->execute($data);

        return DB::transaction(function () use ($identity, $transactionId, $before, $read): PaymentAssociationResolution {
            $scopeId = $this->lock->acquire($identity->canonical_account_key, $identity->environment);
            [$data, $after, $preparation, $binding] = $this->snapshot($identity->id, $transactionId);
            if (! hash_equals($before, $after) || $read->submitted_at === null
                || $read->submitted_at->lessThanOrEqualTo($preparation->prepared_at) || $read->submitted_at->greaterThan($read->read_at)
                || $read->canonical_account_key !== $binding->canonical_account_key
                || $read->transaction_id !== $transactionId || $read->transaction_type !== $data->expected_transaction_type
                || $read->merchant_reference !== $data->expected_merchant_reference
                || $read->authorized_amount_minor !== $data->expected_amount_minor
                || $read->original_transaction_id !== null || $read->currency !== $data->expected_currency) {
                $this->reject();
            }
            $currency = $this->currency->assess($binding, $read);
            $facts = [
                'version' => 1, 'transaction_id' => $read->transaction_id, 'transaction_type' => $read->transaction_type,
                'transaction_status' => $read->transaction_status, 'merchant_reference' => $read->merchant_reference,
                'original_transaction_id' => $read->original_transaction_id, 'original_operation_id' => null,
                'authorized_amount_minor' => $read->authorized_amount_minor, 'settlement_amount_minor' => $read->settlement_amount_minor,
                'currency_policy' => $currency, 'payment_rail' => $read->payment_rail,
                'submitted_at' => $read->submitted_at->format('Y-m-d\TH:i:s.u\Z'), 'read_at' => $read->read_at->format('Y-m-d\TH:i:s.u\Z'),
                'prepared_at' => $preparation->prepared_at->format('Y-m-d\TH:i:s.u\Z'), 'provenance' => 'preparation_only',
                'dispatch_verified' => false, 'operation_verified' => false, 'financial_effects_verified' => false,
            ];
            $candidate = new PaymentTransactionAssociation([
                'payment_association_scope_id' => $scopeId, 'payment_dispatch_preparation_id' => $preparation->id,
                'payment_operation_id' => $preparation->payment_operation_id,
                'transaction_key' => $this->ledger->fingerprint(['association_transaction_v1' => $transactionId]),
                'evidence_fingerprint' => $this->ledger->fingerprint($facts), 'facts' => $facts,
                'currency_qualified' => $currency['currency_qualified'], 'recorded_at' => CarbonImmutable::now('UTC'),
            ]);
            PaymentTransactionAssociation::query()->insertOrIgnore($candidate->getAttributes());
            // Confirm persistence even on an ignored insertion; a failed store cannot return an assessment.
            PaymentTransactionAssociation::where('payment_dispatch_preparation_id', $preparation->id)
                ->where('evidence_fingerprint', $candidate->evidence_fingerprint)->lockForUpdate()->sole();

            return $this->resolver->underLock($preparation, $scopeId);
        }, 3);
    }

    private function snapshot(int $preparationId, string $transactionId): array
    {
        $identity = PaymentDispatchPreparation::findOrFail($preparationId);
        $operationIdentity = PaymentOperation::findOrFail($identity->payment_operation_id);
        $intent = PaymentIntent::whereKey($operationIdentity->payment_intent_id)->lockForUpdate()->firstOrFail();
        $operation = PaymentOperation::whereKey($operationIdentity->id)->lockForUpdate()->firstOrFail();
        $reference = PaymentOperationReference::whereKey($identity->payment_operation_reference_id)->lockForUpdate()->firstOrFail();
        $preparation = PaymentDispatchPreparation::whereKey($identity->id)->lockForUpdate()->firstOrFail();
        $binding = GatewayAccountBinding::findOrFail($preparation->gateway_account_binding_id);
        $this->scope->assertCurrent($operation, $binding);
        $type = match ($operation->purpose) {
            PaymentOperationPurpose::Sale => 'authCaptureTransaction',
            PaymentOperationPurpose::Authorize => 'authOnlyTransaction',
            default => null,
        };
        $frozen = $preparation->prepared_scope;
        $expected = [
            'operation_id' => $operation->id, 'operation_uuid' => $operation->uuid,
            'intent_id' => $intent->id, 'intent_uuid' => $intent->uuid,
            'gateway_account_binding_id' => $binding->id, 'canonical_account_key' => $binding->canonical_account_key,
            'environment' => $binding->environment, 'merchant_binding_fingerprint' => $intent->merchant_binding_fingerprint,
            'order_snapshot_fingerprint' => $intent->order_snapshot_fingerprint,
            'operation_request_fingerprint' => $operation->request_fingerprint,
            'payment_operation_reference_id' => $reference->id, 'merchant_reference' => $reference->reference,
            'purpose' => $operation->purpose->value, 'amount_minor' => $operation->amount_minor,
            'currency' => $intent->currency, 'executor_key' => $operation->executor_key, 'original_operation_id' => null,
        ];
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $frozen) || $frozen[$key] !== $value) {
                $this->reject();
            }
        }
        if ($type === null || $operation->original_operation_id !== null || $preparation->state !== 'prepared_only'
            || $reference->payment_operation_id !== $operation->id || $reference->gateway_account_binding_id !== $binding->id
            || $reference->canonical_account_key !== $binding->canonical_account_key || $reference->environment !== $binding->environment
            || $preparation->canonical_account_key !== $binding->canonical_account_key || $preparation->environment !== $binding->environment
            || $preparation->executor_key !== $operation->executor_key) {
            $this->reject();
        }

        return [new GatewayTransactionReadData($binding->id, $transactionId, $type, null, $operation->amount_minor,
            $intent->currency, expected_merchant_reference: $reference->reference), $this->ledger->fingerprint($expected + ['current_operation_state' => $operation->state->value, 'current_uncertainty_fingerprint' => $operation->uncertainty_fingerprint]), $preparation, $binding];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['payment' => 'Authoritative association evidence or its prepared scope is unavailable.']);
    }
}
