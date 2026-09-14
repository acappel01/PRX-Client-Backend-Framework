<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Patients\PatientResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('portalAccount')->label('Portal account and security')
                ->visible(fn (): bool => $this->getRecord()->portalAccount !== null
                    && (auth()->user()?->can('view', $this->getRecord()->portalAccount) ?? false))
                ->url(fn (): ?string => $this->getRecord()->portalAccount
                    ? PatientResource::getUrl('view', ['record' => $this->getRecord()->portalAccount]) : null),
        ];
    }
}
