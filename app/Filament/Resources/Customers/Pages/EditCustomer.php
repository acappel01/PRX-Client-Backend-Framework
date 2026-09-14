<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\UpdateCustomerAction;
use App\Data\Customers\UpdateCustomerData;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'date_of_birth'] as $field) {
            $data[$field] = $this->getRecord()->getAttribute($field);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data = Arr::only($data, ['first_name', 'last_name', 'email', 'phone', 'date_of_birth']);

        return app(UpdateCustomerAction::class)->execute($record, UpdateCustomerData::validateAndCreate($data));
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
