<?php

namespace App\Actions\Customers;

use App\Actions\Concerns\Transacts;
use App\Models\Customer;
use App\Models\Patient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
                // An outer claim transaction may already have a repeatable-read
                // snapshot. Recheck the known primary key against current state.
                $existing = Customer::withTrashed()->whereKey($existing->getKey())
                    ->where('portal_account_id', $account->getKey())->lockForUpdate()->first();
            }

            if ($existing !== null) {
                return $this->active($existing);
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
            try {
                // Do not lock a missing unique-index range. A savepoint lets a
                // losing insert recover without aborting the outer claim.
                DB::transaction(fn () => $customer->save());
            } catch (UniqueConstraintViolationException $exception) {
                // A concurrent backfill may have committed while we waited for
                // the account lock, after the outer transaction's snapshot.
                $existing = Customer::withTrashed()->where('portal_account_id', $account->getKey())
                    ->lockForUpdate()->first();
                if ($existing === null) {
                    throw $exception;
                }

                return $this->active($existing);
            }

            return $customer;
        });
    }

    private function active(Customer $customer): Customer
    {
        if ($customer->trashed()) {
            throw ValidationException::withMessages(['customer' => 'Restore the existing customer before linking this account.']);
        }

        return $customer;
    }
}
