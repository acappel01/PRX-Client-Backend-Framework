<?php

namespace App\Filament\Resources\Patients\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->since()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->getStateUsing(fn ($record) => trim("{$record->first_name} {$record->last_name}")),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('date_of_birth')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('has_prx_chart')
                    ->label('PRX chart')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->hasPrxChart()),
                IconColumn::make('prx_chart_collision_flagged')
                    ->label('Collision')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
                TextColumn::make('prx_patient_number')
                    ->label('PRX patient no.')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('prx_patient_chart_id')
                    ->label('Chart ID')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('prx_patient_id')
                    ->label('PRX patient id')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('has_prx_chart')
                    ->label('Has PRX chart')
                    ->query(fn (Builder $query) => $query->whereNotNull('prx_patient_chart_id')),
                Filter::make('collision_flagged')
                    ->label('Collision flagged')
                    ->query(fn (Builder $query) => $query->where('prx_chart_collision_flagged', true)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
