<?php

namespace App\Actions\Checkout;

use App\Actions\Customers\LinkCustomerToClaimedLeadAction;
use App\Actions\Customers\MapCustomerToProviderAction;
use App\Actions\Exceptions\ActionException;
use App\Data\Checkout\CheckoutResultData;
use App\Enums\EncounterStatus;
use App\Enums\LeadStatus;
use App\Enums\OrderStatus;
use App\Models\Commerce\Cart;
use App\Models\Commerce\CheckoutAttempt;
use App\Models\Commerce\CheckoutReconciliationAudit;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Lead;
use App\Models\Patient;
use App\Services\Checkout\CheckoutFingerprint;
use App\Services\Checkout\CheckoutProviderBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local-only finalization from the immutable provider receipt, also used for repair. */
class FinalizePrescribeRxCheckoutAction
{
    public function execute(CheckoutAttempt $reference, string $providerInstanceKey, ?string $reconciliationReason = null): CheckoutResultData
    {
        $operation = fn () => $this->finalize($reference, $providerInstanceKey, $reconciliationReason);

        // Repair is local-only. Do not replay model observers/workflow dispatchers
        // that can send CRM/SMS/webhooks; ordinary checkout retains its events.
        return $reconciliationReason === null ? $operation() : Model::withoutEvents($operation);
    }

    private function finalize(CheckoutAttempt $reference, string $providerInstanceKey, ?string $reconciliationReason): CheckoutResultData
    {
        return DB::transaction(function () use ($reference, $providerInstanceKey, $reconciliationReason): CheckoutResultData {
            // Read routing refs afresh, then acquire the same Lead -> Cart -> Attempt
            // order used by submission. Model guards make these references immutable.
            $initial = CheckoutAttempt::findOrFail($reference->id);
            $lead = Lead::query()->lockForUpdate()->findOrFail($initial->lead_id);
            $cart = Cart::query()->lockForUpdate()->findOrFail($initial->cart_id);
            $attempt = CheckoutAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->lead_id !== $lead->id || $attempt->cart_id !== $cart->id) {
                throw ActionException::failed('The checkout ownership could not be verified.', 409);
            }
            $instance = app(CheckoutProviderBinding::class)->recorded($attempt, $providerInstanceKey);
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            if ($attempt->status === 'completed') {
                if (! is_array($attempt->result) || ($attempt->result['order_uuid'] ?? null) !== $order->uuid) {
                    throw ActionException::failed('The saved checkout result could not be verified.', 409);
                }

                return CheckoutResultData::from($attempt->result);
            }
            if (! in_array($attempt->status, ['submitting', 'unknown'], true)
                || $order->encounter_id !== null || $order->status !== OrderStatus::Pending
                || ! filled($lead->cart_ulid) || ! hash_equals($lead->cart_ulid, $cart->ulid)
                || $cart->successor_cart_id !== null) {
                throw ActionException::failed('This order requires confirmation before it can be updated. Please contact support.', 409);
            }
            if ($lead->patient_id === null && ($order->customer_id !== null || $order->patient_id !== null || $lead->customer_id !== null)) {
                throw ActionException::failed('We could not verify the owner of this checkout. Please contact support.', 409);
            }
            $orderItems = $order->items()->orderBy('id')->lockForUpdate()->get();
            if (! is_string($attempt->order_fingerprint)
                || ! hash_equals($attempt->order_fingerprint, CheckoutFingerprint::order($order, $orderItems))) {
                throw ActionException::failed('The submitted order snapshot has changed. Please contact support.', 409);
            }
            $receipt = $attempt->provider_receipt;
            $fields = ['encounter_id', 'encounter_number', 'patient_id', 'status'];
            if (! is_array($receipt) || count($receipt) !== count($fields)) {
                throw ActionException::failed('No complete provider receipt is available for local reconciliation.', 409);
            }
            foreach ($fields as $field) {
                if (! isset($receipt[$field]) || ! is_string($receipt[$field]) || trim($receipt[$field]) === '') {
                    throw ActionException::failed('No complete provider receipt is available for local reconciliation.', 409);
                }
            }
            $beforeStatus = $attempt->status;
            $encounter = Encounter::create([
                'uuid' => (string) Str::uuid(),
                'lead_id' => $lead->id,
                'prescribe_rx_encounter_id' => $receipt['encounter_id'],
                'prescribe_rx_patient_id' => $receipt['patient_id'],
                'prescribe_rx_encounter_type_id' => $attempt->provider_encounter_type_id,
                'status' => EncounterStatus::Submitted,
                'submitted_at' => $attempt->receipt_received_at ?? $attempt->submitted_at,
                'is_sandbox' => $attempt->provider_environment === 'sandbox',
                'total_amount' => $order->total_amount,
                'metadata' => ['checkout_context_uuid' => $attempt->uuid],
            ]);
            $order->update(['encounter_id' => $encounter->id]);
            $lead->update([
                'status' => LeadStatus::HandedOff->value,
                'prescribe_rx_encounter_id' => $receipt['encounter_id'],
                'prescribe_rx_patient_id' => $receipt['patient_id'],
                'handed_off_at' => $attempt->receipt_received_at ?? $attempt->submitted_at,
            ]);
            if ($lead->patient_id !== null) {
                $customer = app(LinkCustomerToClaimedLeadAction::class)->execute(Patient::findOrFail($lead->patient_id), $lead);
                app(MapCustomerToProviderAction::class)->execute($customer, $instance, $receipt['patient_id']);
            }
            $result = CheckoutResultData::from([
                'order_uuid' => $order->uuid,
                'checkout_path' => 'prx',
                'prescribe_rx' => $receipt,
            ]);
            $attempt->update(['status' => 'completed', 'result' => $result->toArray(), 'completed_at' => now()]);
            $items = $cart->items()->orderBy('id')->lockForUpdate()->get();
            if (hash_equals($attempt->cart_fingerprint, CheckoutFingerprint::cart($cart, $items))) {
                $cart->items()->whereIn('id', $items->modelKeys())->delete();
            }
            if ($reconciliationReason !== null) {
                if (trim($reconciliationReason) === '' || mb_strlen($reconciliationReason) > 1000) {
                    throw ActionException::failed('A reconciliation reason of at most 1000 characters is required.', 422);
                }
                CheckoutReconciliationAudit::create([
                    'checkout_attempt_id' => $attempt->id,
                    'provider_instance_id' => $instance->id,
                    'before_status' => $beforeStatus,
                    'after_status' => 'completed',
                    'reason' => $reconciliationReason,
                    'source' => 'console',
                    'created_at' => now(),
                ]);
            }

            return $result;
        }, 3);
    }
}
