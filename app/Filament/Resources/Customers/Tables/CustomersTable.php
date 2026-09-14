<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('uuid')->label('Customer ID')->searchable()->toggleable(),
            TextColumn::make('full_name')->label('Name'),
            TextColumn::make('email')->placeholder('—'),
            TextColumn::make('phone')->placeholder('—')->toggleable(),
            TextColumn::make('prx_patient_number')->label('PRX patient number')->searchable()->placeholder('—'),
            IconColumn::make('portal_account_id')->label('Portal account')->boolean()
                ->getStateUsing(fn ($record): bool => $record->portal_account_id !== null),
            TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Customer ID or PRX patient number')
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }
}
