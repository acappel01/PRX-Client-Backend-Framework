<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Actions\Customers\SaveCustomerAddressAction;
use App\Data\Customers\UpsertCustomerAddressData;
use App\Models\CustomerAddress;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        $customer = $this->getOwnerRecord();
        $schema = [
            Select::make('kind')->options(['shipping' => 'Shipping', 'billing' => 'Billing'])->required(),
            TextInput::make('address.recipient_name')->label('Recipient')->maxLength(200),
            TextInput::make('address.line1')->label('Address line 1')->required()->maxLength(255),
            TextInput::make('address.line2')->label('Address line 2')->maxLength(255),
            TextInput::make('address.city')->label('City')->required()->maxLength(100),
            TextInput::make('address.region')->label('State / region')->maxLength(100),
            TextInput::make('address.postal_code')->label('Postal code')->maxLength(32),
            TextInput::make('address.country_code')->label('Country code')->length(2)->regex('/^[A-Za-z]{2}$/')->required(),
            Toggle::make('is_default')->label('Default for this address type'),
        ];

        return $table->columns([
            TextColumn::make('kind')->badge(),
            TextColumn::make('address.recipient_name')->label('Recipient'),
            TextColumn::make('address.line1')->label('Address'),
            TextColumn::make('address.city')->label('City'),
            TextColumn::make('address.country_code')->label('Country'),
            IconColumn::make('is_default')->boolean()->label('Default'),
        ])->headerActions([
            Action::make('addAddress')->label('Add address')->schema($schema)
                ->visible(fn (): bool => auth()->user()?->can('update', $customer) ?? false)
                ->action(function (array $data) use ($customer): void {
                    Gate::authorize('update', $customer);
                    app(SaveCustomerAddressAction::class)->execute($customer, UpsertCustomerAddressData::validateAndCreate($data));
                }),
        ])->recordActions([
            Action::make('editAddress')->label('Edit')->schema($schema)
                ->visible(fn (): bool => auth()->user()?->can('update', $customer) ?? false)
                ->fillForm(fn (CustomerAddress $record): array => [
                    'kind' => $record->kind,
                    'address' => $record->address,
                    'is_default' => $record->is_default,
                ])
                ->action(function (CustomerAddress $record, array $data) use ($customer): void {
                    Gate::authorize('update', $customer);
                    app(SaveCustomerAddressAction::class)->execute($customer, UpsertCustomerAddressData::validateAndCreate($data), $record);
                }),
        ]);
    }
}
