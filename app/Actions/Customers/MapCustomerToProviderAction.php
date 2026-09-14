<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerProviderLink;
use App\Models\ProviderInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Trusted local mapping; does not fetch a provider or mutate account entitlement. */
class MapCustomerToProviderAction
{
    public function execute(Customer $customer, ProviderInstance $instance, string $chartId, ?string $patientId = null, ?string $patientNumber = null): CustomerProviderLink
    {
        Validator::make(['chart_id' => $chartId, 'patient_id' => $patientId, 'patient_number' => $patientNumber], [
            'chart_id' => ['required', 'string', 'max:64', 'regex:/^\S+$/u'],
            'patient_id' => ['nullable', 'string', 'max:64', 'regex:/^\S+$/u'],
            'patient_number' => ['nullable', 'string', 'max:64', 'regex:/^\S+$/u'],
        ])->validate();

        return DB::transaction(function () use ($customer, $instance, $chartId, $patientId, $patientNumber) {
            // Lock existing primary keys, never a missing unique-index range.
            $customer = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $instance = ProviderInstance::query()->whereKey($instance->getKey())->lockForUpdate()->firstOrFail();
            $links = CustomerProviderLink::query()->where('provider_instance_id', $instance->id)
                ->where(fn ($query) => $query->where('customer_id', $customer->id)->orWhere('chart_id', $chartId))
                ->lockForUpdate()->get();
            if ($links->isNotEmpty()) {
                $link = $links->first();
                if ($links->count() !== 1 || $link->customer_id !== $customer->id || $link->chart_id !== $chartId
                    || ($patientId !== null && $link->patient_id !== $patientId)
                    || ($patientNumber !== null && $link->patient_number !== $patientNumber)) {
                    throw ValidationException::withMessages(['provider_link' => 'Provider mapping conflicts with an existing reference. Reconcile explicitly.']);
                }

                return $link;
            }

            return CustomerProviderLink::create([
                'customer_id' => $customer->id, 'provider_instance_id' => $instance->id,
                'chart_id' => $chartId, 'patient_id' => $patientId, 'patient_number' => $patientNumber,
            ]);
        }, 3);
    }
}
