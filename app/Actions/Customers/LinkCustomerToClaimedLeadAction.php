<?php

namespace App\Actions\Customers;

use App\Actions\Concerns\Transacts;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Patient;
use Illuminate\Validation\ValidationException;

/**
 * Commerce ownership follows an existing verified portal claim. This action
 * grants no chart entitlement and must never be called from anonymous intake.
 * Reusable after checkout finalization for a Lead that was already claimed.
 */
class LinkCustomerToClaimedLeadAction
{
    use Transacts;

    public function __construct(private readonly EnsureCustomerForPortalAccountAction $customers) {}

    public function execute(Patient $account, Lead $lead): Customer
    {
        return $this->tx(function () use ($account, $lead): Customer {
            // Match the claim/finalization lock order: Lead, Patient, Customer,
            // then existing Encounter/Order rows. Re-read all authorization data.
            $lead = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->first();
            $account = Patient::query()->whereKey($account->getKey())->lockForUpdate()->first();

            if ($lead === null || $account === null
                || $lead->patient_id !== $account->getKey()
                || $account->email_verified_at === null
                || $account->prx_chart_verified_at === null
                || ! is_string($account->prx_patient_chart_id)
                || $account->prx_patient_chart_id === '') {
                throw $this->refusal();
            }

            $customer = $this->customers->execute($account);
            $customer = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->first();

            if ($customer === null || $customer->portal_account_id !== $account->getKey()
                || ($lead->customer_id !== null && $lead->customer_id !== $customer->getKey())) {
                throw $this->refusal();
            }

            // Only checkout-created encounters carry this trustworthy lead_id.
            // The anonymous lead's own provider ids are deliberately never read.
            // Locking enumeration is a current read on MySQL REPEATABLE READ.
            // An earlier claim lookup may have established a snapshot before a
            // checkout committed while we waited for this Lead lock.
            $encounterIds = Encounter::query()->where('lead_id', $lead->getKey())
                ->orderBy('id')->lockForUpdate()->pluck('id');
            $hasChartEvidence = false;
            $orders = collect();

            foreach ($encounterIds as $encounterId) {
                $encounter = Encounter::query()->whereKey($encounterId)->lockForUpdate()->first();
                if ($encounter === null || $encounter->lead_id !== $lead->getKey()) {
                    throw $this->refusal();
                }

                $sameChart = $encounter->prescribe_rx_patient_id === $account->prx_patient_chart_id;
                $hasChartEvidence = $hasChartEvidence || $sameChart;
                $orderIds = Order::query()->where('encounter_id', $encounterId)
                    ->orderBy('id')->lockForUpdate()->pluck('id');

                foreach ($orderIds as $orderId) {
                    $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
                    if ($order === null || $order->encounter_id !== $encounterId || ! $sameChart
                        || ($order->customer_id !== null && $order->customer_id !== $customer->getKey())
                        || ($order->patient_id !== null && $order->patient_id !== $account->getKey())) {
                        throw $this->refusal();
                    }
                    $orders->push($order);
                }
            }

            if (! $hasChartEvidence) {
                throw $this->refusal();
            }

            // Validate the whole set before writes. The enclosing transaction also
            // rolls back token consumption/account provisioning when a claim fails.
            if ($lead->customer_id === null) {
                $lead->forceFill(['customer_id' => $customer->getKey()])->save();
            }
            foreach ($orders as $order) {
                if ($order->customer_id === null) {
                    $order->forceFill(['customer_id' => $customer->getKey()])->save();
                }
            }

            return $customer;
        });
    }

    private function refusal(): ValidationException
    {
        return ValidationException::withMessages([
            'customer' => 'We could not safely connect the commerce records for this claim. Please contact support.',
        ]);
    }
}
