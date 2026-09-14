<?php

namespace App\Filament\Pages\Settings;

use App\Models\ProviderInstance;
use App\Services\PrescribeRx\Client;
use App\Services\PrescribeRx\Exceptions\PrescribeRxException;
use App\Settings\IntegrationSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @property-read Schema $form
 */
class ManageIntegrations extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integration credentials';

    protected static ?string $slug = 'settings/integrations';

    public function mount(): void
    {
        $this->form->fill(app(IntegrationSettings::class)->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('PrescribeRx (telehealth unified API)')
                    ->description('Sales-organization token used for the unified intake and encounter-type endpoints. Token is stored encrypted at rest.')
                    ->columns(2)
                    ->components([
                        Toggle::make('prescribe_rx_enabled')
                            ->label('Enable integration')
                            ->helperText('When off, the Service throws on every call — useful while waiting for a token.')
                            ->columnSpanFull(),
                        Select::make('prescribe_rx_environment')
                            ->options([
                                'sandbox' => 'Sandbox (demo.prescribe-rx.com)',
                                'production' => 'Production (prescribe-rx.com)',
                            ])
                            ->default('sandbox')
                            ->required()
                            ->native(false)
                            ->hintIcon(Heroicon::InformationCircle, 'PRX API base URL environment. Use Sandbox for testing; Production for live patients.'),
                        TextInput::make('prescribe_rx_api_token')
                            ->label('API token')
                            ->password()
                            ->revealable()
                            ->maxLength(2048)
                            ->hintIcon(Heroicon::InformationCircle, 'Bearer token issued from the Prescribe-Rx admin. Includes the {id}| Sanctum prefix. Works against both environments.')
                            ->helperText('Sales-organization token issued from production (works against either environment). Includes the {id}| Sanctum prefix.')
                            ->columnSpanFull(),
                        TextInput::make('prescribe_rx_sales_org_id')
                            ->label('Sales organization ID (optional)')
                            ->maxLength(64)
                            ->hintIcon(Heroicon::InformationCircle, 'UUID of your sales org in PrescribeRx. Leave blank to use the org associated with the token.')
                            ->helperText('UUID. Leave blank to use the token\'s authenticated org.'),
                        TextInput::make('prescribe_rx_client_id')
                            ->label('Client ID (optional)')
                            ->maxLength(64)
                            ->hintIcon(Heroicon::InformationCircle, 'UUID of the client record in PrescribeRx. Leave blank to use the token\'s default client.')
                            ->helperText('UUID. Leave blank to use the token\'s default client.'),
                        Select::make('prescribe_rx_provider_instance_key')
                            ->label('API checkout provider instance')
                            ->options(fn (): array => ProviderInstance::query()
                                ->where('provider', 'prescribe_rx')
                                ->whereIn('account_type', ['client', 'sales_organization'])
                                ->orderBy('key')->get(['key', 'environment', 'account_type'])
                                ->mapWithKeys(fn (ProviderInstance $instance): array => [
                                    $instance->key => $instance->key.' ('.$instance->environment.', '.$instance->account_type.')',
                                ])->all())
                            ->searchable()
                            ->native(false)
                            ->helperText('Choose a registered instance matching the API environment and explicit Client ID, or Sales organization ID when Client ID is blank. Required for new API checkout; existing attempts retain their original instance.')
                            ->columnSpanFull(),
                        TextInput::make('prescribe_rx_encounter_type_id')
                            ->label('Universal encounter type ID')
                            ->maxLength(64)
                            ->hintIcon(Heroicon::InformationCircle, 'UUID of the encounter type in PrescribeRx that covers all products. All checkout submissions use this type; intake question visibility is handled dynamically on the frontend.')
                            ->helperText('UUID from PrescribeRx → Encounter Types. All products route to this single type.')
                            ->columnSpanFull(),
                    ]),
                Section::make('PrescribeRx embed')
                    ->description('The embed code generated in prescribe-rx admin (/admin/embed-configs) is what the public-site iframe widget loads. It maps to a single encounter type and tenant, and skip-step / dynamic-question logic is handled inside the embed based on the products we pass via the SDK.')
                    ->columns(2)
                    ->components([
                        TextInput::make('prescribe_rx_embed_code')
                            ->label('Embed code')
                            ->password()
                            ->revealable()
                            ->maxLength(64)
                            ->hintIcon(Heroicon::InformationCircle, 'Short code from PrescribeRx admin → Embed Configs, e.g. LbbGb7jFpFmP. Stored encrypted.')
                            ->helperText('e.g. LbbGb7jFpFmP. Stored encrypted.')
                            ->columnSpanFull(),
                        TextInput::make('prescribe_rx_webhook_secret')
                            ->label('Webhook signing secret')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->hintIcon(Heroicon::InformationCircle, 'HMAC-SHA256 secret for verifying the X-PrescribeRx-Signature header on inbound webhooks. Stored encrypted.')
                            ->helperText('HMAC-SHA256 secret used to verify the X-PrescribeRx-Signature header on inbound webhooks. Stored encrypted.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $settings = app(IntegrationSettings::class);
            foreach ($this->form->getState() as $key => $value) {
                if (property_exists($settings, $key)) {
                    $settings->{$key} = $value;
                }
            }
            $settings->save();
        } catch (ValidationException $e) {
            // Rethrow ahead of the catch-all: a ValidationException knows which
            // field it belongs to, and converting it into a page-level
            // notification throws that away, leaving the operator hunting for
            // the control at fault. Every settings page had this; found via the
            // palette rule on ManageTheme, which looked inert because its
            // message was being swallowed here.
            throw $e;
        } catch (Throwable $e) {
            Notification::make()
                ->title('Could not save integration settings')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Integration settings saved')
            ->success()
            ->send();
    }

    /**
     * Header action: ping the prescribe-rx API using whatever's currently in
     * the form (no Save required first). Reports the encounter-types count
     * on success or the API's error message verbatim on failure.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('testPrescribeRx')
                ->label('Test prescribe-rx connection')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->action(function () {
                    $form = $this->form->getState();

                    // Build a transient settings instance from the form state
                    // (don't persist) so the user can test before saving.
                    $tempSettings = clone app(IntegrationSettings::class);
                    foreach ($form as $key => $value) {
                        if (property_exists($tempSettings, $key)) {
                            $tempSettings->{$key} = $value;
                        }
                    }

                    if (! $tempSettings->prescribe_rx_enabled) {
                        Notification::make()
                            ->title('Integration is not enabled')
                            ->body('Toggle "Enable integration" on, then test.')
                            ->warning()
                            ->send();

                        return;
                    }
                    if (empty($tempSettings->prescribe_rx_api_token)) {
                        Notification::make()
                            ->title('No token configured')
                            ->body('Paste a sales-organization API token, then test.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $client = new Client($tempSettings);
                        $types = $client->listEncounterTypes();

                        $envLabel = $tempSettings->prescribe_rx_environment;
                        $url = config("prescribe-rx.urls.{$envLabel}");

                        Notification::make()
                            ->title('Connected to prescribe-rx ('.$envLabel.')')
                            ->body(sprintf(
                                '%s · returned %d encounter type(s)',
                                $url,
                                count($types),
                            ))
                            ->success()
                            ->send();
                    } catch (PrescribeRxException $e) {
                        $body = "HTTP {$e->httpStatus}: {$e->getMessage()}";
                        if ($e->errors !== null) {
                            $body .= "\n\nFields: ".collect($e->errors)
                                ->keys()
                                ->take(5)
                                ->implode(', ');
                        }

                        Notification::make()
                            ->title('PrescribeRx connection failed')
                            ->body($body)
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Unexpected error')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
