<?php

namespace App\Actions\Customers;

use App\Actions\Concerns\Transacts;
use App\Data\Customers\UpsertCustomerAddressData;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Validation\ValidationException;

class SaveCustomerAddressAction
{
    use Transacts;

    public function execute(Customer $customer, UpsertCustomerAddressData $data, ?CustomerAddress $address = null): CustomerAddress
    {
        return $this->tx(function () use ($customer, $data, $address): CustomerAddress {
            $customer = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            if ($address !== null) {
                $address = $customer->addresses()->whereKey($address->getKey())->first();
                if ($address === null) {
                    throw ValidationException::withMessages(['address' => 'That address does not belong to this customer.']);
                }
            }

            if ($data->is_default) {
                $customer->addresses()->where('kind', $data->kind)->update(['is_default' => false]);
            }

            $address ??= $customer->addresses()->make();
            $fields = $data->address;
            $fields['country_code'] = strtoupper($fields['country_code']);
            $address->fill(['kind' => $data->kind, 'address' => $fields, 'is_default' => $data->is_default]);
            $address->save();

            return $address->refresh();
        });
    }
}
