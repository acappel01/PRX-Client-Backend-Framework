<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer details')->columns(2)->schema([
                TextEntry::make('full_name')->label('Name'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('date_of_birth')->date()->placeholder('—'),
                TextEntry::make('uuid')->label('Customer ID')->copyable(),
                TextEntry::make('created_at')->dateTime(),
            ]),
            Section::make('Provider references')->columns(2)->schema([
                TextEntry::make('provider_environment')->label('Environment')->placeholder('Not recorded'),
                TextEntry::make('prx_patient_chart_id')->label('PRX chart ID')->placeholder('—'),
                TextEntry::make('prx_patient_id')->label('PRX patient ID')->placeholder('—'),
                TextEntry::make('prx_patient_number')->label('PRX patient number')->placeholder('—'),
            ]),
            Section::make('Portal account')
                ->description('An imported or manually created customer does not automatically have a portal account. Account enrollment and clinical access require their existing verification flows.')
                ->schema([
                    TextEntry::make('portal_account_status')
                        ->label('Account association')
                        ->state(fn ($record): string => $record->portal_account_id ? 'Associated' : 'No portal account'),
                ]),
        ]);
    }
}
