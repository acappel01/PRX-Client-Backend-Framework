<?php

namespace App\Actions\Payments;

use App\Contracts\Payments\PaymentDispatchLineageResolver;
use App\Contracts\Payments\PaymentDispatchTransport;
use App\Data\Payments\PaymentDispatchRequest;
use App\Enums\Payments\PaymentOperationState;
use App\Models\Commerce\Order;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Services\Payments\PaymentAssociationLock;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationReferenceScope;
use App\Services\Payments\ResolvePaymentDispatchOriginal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** No route/job/default transport. Claim commit precedes the only transport call; replay never calls it. */
class DispatchPreparedPaymentAction
{
    public function __construct(private readonly PaymentDispatchTransport $transport, private readonly PaymentOperationReferenceScope $scope, private readonly PaymentLedgerScope $ledger, private readonly ?PaymentDispatchLineageResolver $lineage = null) {}

    public function execute(string $preparationUuid, string $executorKey): PaymentDispatchAttempt
    {
        if (DB::transactionLevel() !== 0 || config('payments.dispatch_enabled', false) !== true) {
            $this->reject();
        }
        if (! Str::isUuid($preparationUuid) || ! preg_match('/\A[a-z][a-z0-9_.:-]{0,63}\z/', $executorKey)) {
            $this->reject();
        }
        $transportKey = $this->transport->key();
        if (! preg_match('/\A[a-z][a-z0-9_.:-]{0,63}\z/', $transportKey)) {
            $this->reject();
        }
        [$attempt,$owned] = DB::transaction(function () use ($preparationUuid, $executorKey, $transportKey): array {
            $identity = PaymentDispatchPreparation::where('uuid', strtolower($preparationUuid))->firstOrFail();
            app(PaymentAssociationLock::class)->acquire($identity->canonical_account_key, $identity->environment);
            $opIdentity = PaymentOperation::findOrFail($identity->payment_operation_id);
            $intentIdentity = PaymentIntent::findOrFail($opIdentity->payment_intent_id);
            // Serialize all intents for this commercial order, even across merchant accounts.
            Order::withTrashed()->whereKey($intentIdentity->order_id)->lockForUpdate()->firstOrFail();
            $intent = PaymentIntent::whereKey($opIdentity->payment_intent_id)->lockForUpdate()->firstOrFail();
            $operation = PaymentOperation::whereKey($opIdentity->id)->lockForUpdate()->firstOrFail();
            $preparation = PaymentDispatchPreparation::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $prior = PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $preparation->id)->lockForUpdate()->first();
            if ($prior !== null) {
                if ($prior->executor_key !== $executorKey || $prior->transport_key !== $transportKey) {
                    $this->reject();
                }

                return [$prior, false];
            }
            // No split collection or replacement-intent policy is qualified yet. Any earlier
            // attempt (including a decline or unknown outcome) blocks a different intent.
            if (PaymentDispatchAttempt::join('payment_intents as collection_intents', 'collection_intents.id', '=', 'payment_dispatch_attempts.payment_intent_id')
                ->where('collection_intents.order_id', $intent->order_id)
                ->where('payment_dispatch_attempts.payment_intent_id', '!=', $intent->id)->lockForUpdate()->exists()) {
                $this->reject();
            }
            $binding = GatewayAccountBinding::findOrFail($preparation->gateway_account_binding_id);
            $this->scope->assertCurrent($operation, $binding);
            $f = $preparation->prepared_scope;
            if ($preparation->executor_key !== $executorKey || $operation->executor_key !== $executorKey || $operation->state !== PaymentOperationState::Prepared
             || $f['operation_uuid'] !== $operation->uuid || $f['merchant_binding_fingerprint'] !== $intent->merchant_binding_fingerprint
             || $f['order_snapshot_fingerprint'] !== $intent->order_snapshot_fingerprint || $f['operation_request_fingerprint'] !== $operation->request_fingerprint
             || $f['canonical_account_key'] !== $binding->canonical_account_key || $f['gateway_account_binding_id'] !== $binding->id
             || $f['amount_minor'] !== $operation->amount_minor || $f['currency'] !== $intent->currency || $f['purpose'] !== $operation->purpose->value
             || ! $this->transport->supports($f['purpose'])
             || PaymentOperation::where('payment_intent_id', $intent->id)->where('state', PaymentOperationState::Uncertain->value)->lockForUpdate()->exists()) {
                $this->reject();
            }
            $original = null;
            if (! in_array($f['purpose'], ['sale', 'authorize'], true)) {
                $original = ($this->lineage ?? app(ResolvePaymentDispatchOriginal::class))->resolve($preparation);
                if ($original->parent_operation_id !== $operation->original_operation_id || $original->canonical_account_key !== $binding->canonical_account_key || $original->environment !== $binding->environment
                 || ! preg_match('/\A[1-9][0-9]{0,31}\z/', $original->original_transaction_id) || ! preg_match('/\A[a-f0-9]{64}\z/', $original->evidence_fingerprint)) {
                    $this->reject();
                }
            }
            $priorAttempts = PaymentDispatchAttempt::where('payment_intent_id', $intent->id)->lockForUpdate()->get();
            $ancestorIds = $original === null ? [] : array_values(array_unique([$original->parent_operation_id, ...$original->ancestor_operation_ids]));
            foreach ($ancestorIds as $ancestorId) {
                $ancestor = $priorAttempts->firstWhere('payment_operation_id', $ancestorId);
                if (! is_int($ancestorId) || $ancestorId < 1 || $ancestor === null || $ancestor->status !== 'response_observed'
                    || ($ancestor->receipt['response_code'] ?? null) !== '1') {
                    $this->reject();
                }
            }
            if ($original !== null) {
                $parentAttempt = $priorAttempts->firstWhere('payment_operation_id', $original->parent_operation_id);
                if ($parentAttempt->payment_dispatch_preparation_id !== $original->parent_preparation_id
                    || ($parentAttempt->receipt['transaction_id'] ?? null) !== $original->original_transaction_id) {
                    $this->reject();
                }
            }
            foreach ($priorAttempts as $other) {
                if ($original === null || ! in_array($other->payment_operation_id, $ancestorIds, true)) {
                    $this->reject();
                }
            }
            $facts = ['preparation_uuid' => $preparation->uuid, 'operation_uuid' => $operation->uuid, 'gateway_account_binding_id' => $binding->id,
                'canonical_account_key' => $binding->canonical_account_key, 'environment' => $binding->environment, 'merchant_reference' => $f['merchant_reference'],
                'purpose' => $f['purpose'], 'amount_minor' => $f['amount_minor'], 'currency' => $f['currency'], 'original_transaction_id' => $original?->original_transaction_id,
                'merchant_binding_fingerprint' => $intent->merchant_binding_fingerprint, 'original_evidence_fingerprint' => $original?->evidence_fingerprint, 'parent_preparation_id' => $original?->parent_preparation_id];
            $fingerprint = $this->ledger->fingerprint($facts);
            $row = PaymentDispatchAttempt::create(['uuid' => (string) Str::uuid(), 'payment_dispatch_preparation_id' => $preparation->id, 'payment_intent_id' => $intent->id,
                'payment_operation_id' => $operation->id, 'executor_key' => $executorKey, 'transport_key' => $transportKey, 'status' => 'claimed',
                'request_fingerprint' => $fingerprint, 'request_facts' => $facts, 'claimed_at' => CarbonImmutable::now('UTC')]);

            return [$row, true];
        }, 3);
        if (! $owned) {
            return $attempt;
        }
        $f = $attempt->request_facts;
        $request = new PaymentDispatchRequest($attempt->uuid, $f['preparation_uuid'], $f['operation_uuid'], $f['gateway_account_binding_id'], $f['canonical_account_key'], $f['environment'], $f['merchant_reference'], $f['purpose'], $f['amount_minor'], $f['currency'], $f['original_transaction_id'], $attempt->request_fingerprint, $f['merchant_binding_fingerprint']);
        $started = CarbonImmutable::now('UTC');
        $receipt = null;
        $status = 'outcome_unknown';
        try {
            $response = $this->transport->dispatch($request);
            if (! preg_match('/\A[a-f0-9]{64}\z/', $response->request_fingerprint) || ! preg_match('/\A[1-4]\z/', $response->response_code)
             || ($response->transaction_id !== null && ! preg_match('/\A[1-9][0-9]{0,31}\z/', $response->transaction_id))
             || ($response->echoed_ref_id !== null && ! preg_match('/\A[A-Za-z0-9._:-]{1,20}\z/', $response->echoed_ref_id))
             || ($response->response_original_id !== null && ! preg_match('/\A[1-9][0-9]{0,31}\z/', $response->response_original_id))) {
                $this->reject();
            }
            $receipt = ['request_fingerprint' => $response->request_fingerprint, 'response_code' => $response->response_code, 'transaction_id' => $response->transaction_id,
                'echoed_ref_id' => $response->echoed_ref_id, 'response_original_id' => $response->response_original_id];
            if ($response->request_fingerprint !== $request->request_fingerprint || $response->echoed_ref_id !== $request->merchant_reference
             || ($response->response_original_id !== null && $response->response_original_id !== $request->original_transaction_id)
             || ($response->response_code === '1' && $response->transaction_id === null)) {
                $this->reject();
            }
            $status = 'response_observed';
        } catch (Throwable) {/* Never log/chains transport exceptions, raw bodies or credentials. No retry. */
        }

        return DB::transaction(function () use ($attempt, $started, $receipt, $status): PaymentDispatchAttempt {
            $current = PaymentDispatchAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'claimed') {
                $this->reject();
            }
            $current->forceFill(['status' => $status, 'receipt' => $receipt, 'transport_started_at' => $started, 'completed_at' => CarbonImmutable::now('UTC')])->save();

            return $current;
        }, 3);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['payment' => 'Dispatch is disabled, already claimed, or its scope is not qualified.']);
    }
}
