<?php

namespace App\Actions\Customers;

use App\Actions\Concerns\Transacts;
use App\Data\Customers\UpdateCustomerData;
use App\Models\Customer;

class UpdateCustomerAction
{
    use Transacts;

    public function execute(Customer $customer, UpdateCustomerData $data): Customer
    {
        return $this->tx(function () use ($customer, $data): Customer {
            $customer->update($data->toArray());

            return $customer->refresh();
        });
    }
}
