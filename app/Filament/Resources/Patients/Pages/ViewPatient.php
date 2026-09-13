<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Actions\Patient\RevokePatientSessionsAction;
use App\Data\Patient\RequestContext;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPatient extends ViewRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            // Gated on UPDATE, not view: it changes what the patient can do.
            Action::make('signOutEverywhere')
                ->label('Sign out everywhere')
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color('warning')
                ->visible(fn (Patient $record): bool => ! $record->trashed() && (auth()->user()?->can('update', $record) ?? false))
                ->requiresConfirmation()
                ->modalHeading('Sign this patient out everywhere?')
                ->modalDescription('Ends every portal session on this account. The password does not change, so anyone who knows it can sign in again — if the account may be compromised, ask the patient to reset their password from the sign-in page as well.')
                ->modalSubmitActionLabel('Sign out everywhere')
                ->action(function (Patient $record): void {
                    $revoked = app(RevokePatientSessionsAction::class)->execute(
                        $record,
                        (int) auth()->id(),
                        RequestContext::fromRequest(request()),
                    );

                    Notification::make()
                        ->success()
                        ->title($revoked === 1 ? '1 session ended' : "{$revoked} sessions ended")
                        ->body('Recorded in the security history below.')
                        ->send();
                }),
        ];
    }
}
