<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\CreateCustomerAction;
use App\Data\Customers\CreateCustomerData;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateCustomerAction::class)->execute(CreateCustomerData::validateAndCreate(
            Arr::only($data, ['first_name', 'last_name', 'email', 'phone', 'date_of_birth']),
        ));
    }
}
