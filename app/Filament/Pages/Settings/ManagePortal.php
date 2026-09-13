<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Settings\UpdatePortalSettingsAction;
use App\Data\Settings\PortalSettingsData;
use App\Enums\Patient\TwoFactorPolicy;
use App\Settings\PortalSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
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
                Section::make('Two-step verification')
                    ->description('A code from an authenticator app (Google Authenticator, 1Password, Authy…) after the password, with one-time recovery codes for a lost phone.')
                    ->components([
                        Select::make('two_factor_policy')
                            ->label('Two-step verification for patients')
                            ->required()
                            ->native(false)
                            ->options(collect(TwoFactorPolicy::cases())->mapWithKeys(fn (TwoFactorPolicy $policy): array => [$policy->value => $policy->label()])->all())
                            ->helperText('Off: not offered, but patients who already turned it on keep it. Optional: every patient is invited to set it up and can skip. Required: patients without it are sent to set it up on their next screen — they are not signed out.'),
                        TextInput::make('trusted_device_days')
                            ->label('"Trust this browser" lasts')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(PortalSettingsData::TRUSTED_DEVICE_MAX_DAYS)
                            ->suffix('days')
                            ->helperText('After entering a code, a patient can trust their browser and skip the code (never the password) for this long, renewed each time they sign in there. 0 turns the option off. Trust is removed when their password is reset, their email is first verified, two-step verification is turned off, reset or moved to a new phone, you sign them out everywhere, or the account is deleted. 0 to 90.'),
                    ]),

                Section::make('Sessions')
                    ->description('How long a patient stays signed in to the portal. A shorter value applies to patients already signed in on their next request; a longer maximum applies from their next sign-in.')
                    ->columns(2)
                    ->components([
                        TextInput::make('session_idle_minutes')
                            ->label('Sign out after inactivity')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(PortalSettingsData::IDLE_MIN_MINUTES)
                            ->maxValue(PortalSettingsData::IDLE_MAX_MINUTES)
                            ->suffix('minutes')
                            ->helperText('A session unused for this long ends. The portal warns two minutes before. 5 to 240.'),
                        TextInput::make('session_max_hours')
                            ->label('Always sign out after')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(PortalSettingsData::MAX_MIN_HOURS)
                            ->maxValue(PortalSettingsData::MAX_MAX_HOURS)
                            ->suffix('hours')
                            ->helperText('Every session ends this long after sign-in, however active. 1 to 720.'),
                    ]),

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
