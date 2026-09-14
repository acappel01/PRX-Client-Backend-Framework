<?php

namespace App\Actions\Customers;

use App\Actions\Concerns\Transacts;
use App\Data\Customers\CreateCustomerData;
use App\Models\Customer;

class CreateCustomerAction
{
    use Transacts;

    public function execute(CreateCustomerData $data): Customer
    {
        return $this->tx(fn () => Customer::create($data->toArray()));
    }
}
