<?php

namespace App\Filament\Resources\Patients\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('date_of_birth')->date()->placeholder('—'),
                        TextEntry::make('uuid')->label('UUID')->copyable(),
                    ]),

                Section::make('PRX Chart')
                    ->columns(2)
                    ->schema([
                        IconEntry::make('has_prx_chart')
                            ->label('Chart linked')
                            ->boolean()
                            ->getStateUsing(fn ($record) => $record->hasPrxChart()),
                        IconEntry::make('prx_chart_collision_flagged')
                            ->label('Collision flagged')
                            ->boolean()
                            ->trueColor('danger')
                            ->falseColor('success'),
                        TextEntry::make('prx_patient_chart_id')->label('Chart ID')->placeholder('—')->copyable(),
                        TextEntry::make('prx_patient_id')->label('Patient ID')->placeholder('—')->copyable(),
                        TextEntry::make('prx_chart_verified_at')->label('Verified at')->dateTime()->placeholder('—'),
                    ]),

                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('email_verified_at')->label('Email verified')->dateTime()->placeholder('Not verified'),
                        TextEntry::make('two_factor_confirmed_at')
                            ->label('Two-step verification')
                            ->state(fn ($record) => $record->hasTwoFactor() ? $record->two_factor_confirmed_at : null)
                            ->dateTime()
                            ->prefix(fn ($record) => $record->hasTwoFactor() ? 'On since ' : null)
                            ->placeholder('Off'),
                        TextEntry::make('created_at')->label('Registered')->dateTime(),
                        TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                        TextEntry::make('deleted_at')->label('Deleted')->dateTime()->placeholder('—'),
                    ]),
            ]);
    }
}
