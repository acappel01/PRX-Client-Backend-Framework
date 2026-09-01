<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Settings\UpdateBillingSettingsAction;
use App\Data\Settings\BillingSettingsData;
use App\Enums\Payments\PaymentCollector;
use App\Settings\BillingSettings;
use BackedEnum;
use Filament\Forms\Components\Radio;
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
class ManageBilling extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Billing';

    protected static ?string $title = 'Billing settings';

    protected static ?string $slug = 'settings/billing';

    public function mount(): void
    {
        $this->form->fill(app(BillingSettings::class)->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Checkout path')
                    ->description('Controls how orders are submitted at checkout. Changing this affects all new orders immediately.')
                    ->schema([
                        Radio::make('checkout_path')
                            ->label('Default checkout provider')
                            ->options([
                                'prx' => 'Prescribe-Rx (embed handoff)',
                                'local' => 'Local gateway (NMI / Authorize.Net / Stripe / Square)',
                            ])
                            ->descriptions([
                                'prx' => 'Cart is submitted to Prescribe-Rx. PRX collects payment and clinical intake inside its hosted embed. No local card processing.',
                                'local' => 'Payment is captured locally through the configured merchant account. The frontend tokenizes the card via the gateway SDK and sends the token to /api/v1/checkout.',
                            ])
                            ->required(),
                    ]),
                Section::make('Who collects payment')
                    ->description('Separate from the checkout path above, which decides who submits the ORDER. A deployment can route orders through the provider while still taking the card itself, so these are two questions rather than one.')
                    ->schema([
                        Radio::make('payment_collector')
                            ->label('Payment is taken by')
                            ->options(fn (): array => collect(PaymentCollector::cases())
                                ->mapWithKeys(fn (PaymentCollector $c) => [$c->value => $c->label()])
                                ->all())
                            ->descriptions(fn (): array => collect(PaymentCollector::cases())
                                ->mapWithKeys(fn (PaymentCollector $c) => [$c->value => $c->helperText()])
                                ->all())
                            ->required()
                            ->helperText('This is the only input to whether the clinical embed shows its payment step and whether our checkout shows its own — one setting, so the two can never both try to collect. Note the provider only permits their own pre-authorisation inside their embed, so "we collect" means we charge here and report the payment to them as already captured.'),
                    ]),
                Section::make('Upsells & cross-sells')
                    ->description('Suggestions shown in the cart drawer and on the checkout page. Driven entirely by the "Related" / "Pairs With" relations configured on catalog items — nothing is hardcoded.')
                    ->schema([
                        Toggle::make('upsells_enabled')
                            ->label('Show upsell suggestions')
                            ->helperText('When off, the cart and checkout upsell placements are hidden on the frontend.'),
                        TextInput::make('upsells_limit')
                            ->label('Maximum suggestions')
                            ->helperText('Cap on how many suggested items are returned per request (1–12).')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(12)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = BillingSettingsData::validateAndCreate($this->form->getState());
            app(UpdateBillingSettingsAction::class)->execute($data);
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
                ->title('Could not save billing settings')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Billing settings saved')
            ->success()
            ->send();
    }
}
