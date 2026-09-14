<?php

namespace App\Actions\Customers;

use App\Actions\Concerns\Transacts;
use App\Models\Customer;
use App\Models\Patient;
use Illuminate\Validation\ValidationException;

/** Explicit, local-only provisioning. Never resolves a customer by email or grants chart access. */
class EnsureCustomerForPortalAccountAction
{
    use Transacts;

    public function execute(Patient $account, ?string $providerEnvironment = null): Customer
    {
        if ($providerEnvironment !== null && ! in_array($providerEnvironment, ['sandbox', 'production'], true)) {
            throw ValidationException::withMessages(['provider_environment' => 'Choose a valid provider environment.']);
        }

        return $this->tx(function () use ($account, $providerEnvironment): Customer {
            // All invocations for the same account serialize on its existing primary key.
            $account = Patient::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $existing = Customer::withTrashed()->where('portal_account_id', $account->getKey())->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    throw ValidationException::withMessages(['customer' => 'Restore the existing customer before linking this account.']);
                }

                return $existing;
            }

            $customer = new Customer([
                'first_name' => $account->first_name,
                'last_name' => $account->last_name,
                'email' => $account->email,
                'phone' => $account->phone,
                'date_of_birth' => $account->date_of_birth?->toDateString(),
                // Historical references are copied, not verified or reinterpreted.
                'provider_environment' => $providerEnvironment,
                'prx_patient_chart_id' => $account->prx_patient_chart_id,
                'prx_patient_id' => $account->prx_patient_id,
                'prx_patient_number' => $account->prx_patient_number,
            ]);
            $customer->portalAccount()->associate($account);
            $customer->save();

            return $customer;
        });
    }
}
