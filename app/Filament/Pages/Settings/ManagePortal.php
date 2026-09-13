<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Settings\UpdatePortalSettingsAction;
use App\Data\Settings\PortalSettingsData;
use App\Settings\PortalSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @property-read Schema $form
 */
class ManagePortal extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 57;

    protected static ?string $navigationLabel = 'Patient portal';

    protected static ?string $title = 'Patient portal';

    protected static ?string $slug = 'settings/portal';

    public function mount(): void
    {
        $this->form->fill(app(PortalSettings::class)->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Security history')
                    ->description('Every sign-in, failed sign-in, sign-out and account change is recorded with the IP address and browser it came from. Patients see their own history in the portal; operators see it on the patient record.')
                    ->components([
                        TextInput::make('security_events_retention_days')
                            ->label('Keep security history for')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(PortalSettingsData::RETENTION_MIN_DAYS)
                            ->maxValue(PortalSettingsData::RETENTION_MAX_DAYS)
                            ->suffix('days')
                            ->helperText('Older entries are deleted every night. IP addresses identify people, so keep them only as long as you need to investigate an account problem. 30 to 2555 days; there is no "forever".'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = PortalSettingsData::validateAndCreate($this->form->getState());
            app(UpdatePortalSettingsAction::class)->execute($data);
        } catch (ValidationException $e) {
            // Rethrown so the message lands on the field — see ManageSeo::save().
            throw $e;
        } catch (Throwable $e) {
            Notification::make()
                ->title('Could not save patient portal settings')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Patient portal settings saved')->success()->send();
    }
}
