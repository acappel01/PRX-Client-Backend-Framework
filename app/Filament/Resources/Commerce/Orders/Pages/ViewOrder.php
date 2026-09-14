<?php

namespace App\Filament\Resources\Commerce\Orders\Pages;

use App\Filament\Resources\Commerce\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function authorizeAccess(): void
    {
        abort_if($this->getRecord()->trashed(), 404);
        parent::authorizeAccess();
    }

    // The existing edit page's shipment mutation actions are never mounted here.
    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // This view is an explicit infolist, never a generic attribute dump.
        return [];
    }
}
