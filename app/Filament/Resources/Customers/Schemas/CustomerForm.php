<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer details')
                ->description('Contact details for commerce. Changing these does not change a portal login or grant access to a clinical record.')
                ->columns(2)
                ->schema([
                    TextInput::make('first_name')->required()->maxLength(100),
                    TextInput::make('last_name')->required()->maxLength(100),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('phone')->tel()->maxLength(32),
                    DatePicker::make('date_of_birth')->label('Date of birth')->maxDate(today()),
                ]),
        ]);
    }
}
