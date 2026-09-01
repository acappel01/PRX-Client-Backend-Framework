<?php

namespace App\Filament\Resources\Payments\MerchantAccounts\Schemas;

use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MerchantAccountForm
{
    /** Handles both string state (user picking from select) and enum state (Filament hydrating from model). */
    private static function isGateway(Get $get, GatewayProvider $provider): bool
    {
        $value = $get('gateway_provider');

        if ($value instanceof GatewayProvider) {
            return $value === $provider;
        }

        return $value === $provider->value;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Merchant account')
                    ->vertical()
                    ->persistTabInQueryString('merchant-account-tab')
                    ->columnSpanFull()
                    ->tabs([

                        // ── Details ───────────────────────────────────
                        Tab::make('Details')
                            ->icon(Heroicon::DocumentText)
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(191)
                                    ->hintIcon(Heroicon::InformationCircle, 'Internal label for this gateway account, e.g. "NMI Primary" or "Stripe Sandbox".')
                                    ->columnSpanFull(),
                                Select::make('gateway_provider')
                                    ->options(GatewayProvider::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->hintIcon(Heroicon::InformationCircle, 'Payment gateway type. Determines which credential fields appear on the Credentials tab.')
                                    ->helperText('After selecting a gateway, its credential fields appear on the Credentials tab.'),
                                Select::make('environment')
                                    ->options(GatewayEnvironment::class)
                                    ->required()
                                    ->native(false)
                                    ->hintIcon(Heroicon::InformationCircle, 'Use Sandbox for testing; switch to Production before going live.')
                                    ->default(GatewayEnvironment::Sandbox->value),
                                Toggle::make('is_active')
                                    ->default(true)
                                    ->helperText('Inactive accounts are skipped during payment routing.'),
                                Toggle::make('is_default')
                                    ->helperText('Only one account should be set as default.'),
                            ]),

                        // ── Credentials ───────────────────────────────
                        Tab::make('Credentials')
                            ->icon(Heroicon::Key)
                            ->schema([

                                // Hint shown when no gateway has been selected yet
                                // ── Telehealth provider link ──────────────────────
                                Section::make('Telehealth provider link')
                                    ->description('Required before this account can take payment on our own checkout.')
                                    ->components([
                                        TextInput::make('provider_merchant_profile_id')
                                            ->label('Provider merchant profile ID')
                                            ->maxLength(64)
                                            ->helperText('The telehealth provider\'s own id for THIS merchant account. Both sides must be the same account before they will link a card we vaulted — they validate it when the intake finalises. Without it a stored card is ours alone, and they cannot capture it later or rebill a subscription against it. Leave blank only while the provider collects payment itself.'),
                                    ]),

                                Section::make('Credentials')
                                    ->description('Select a gateway provider on the Details tab — the matching credential fields will appear here.')
                                    ->visible(fn (Get $get): bool => blank($get('gateway_provider')))
                                    ->components([
                                        Placeholder::make('no_gateway_hint')
                                            ->label('')
                                            ->content('No gateway selected. Choose NMI, Authorize.Net, Stripe, or Square from the Details tab.'),
                                    ]),

                                // ── NMI credentials ───────────────────────────────
                                Section::make('NMI credentials')
                                    ->description('NMI Direct Post API credentials.')
                                    ->visible(fn (Get $get): bool => self::isGateway($get, GatewayProvider::Nmi))
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('nmi_security_key')
                                            ->label('Security Key')
                                            ->hintIcon(Heroicon::InformationCircle, 'NMI gateway security key for Direct Post API. Found in NMI control panel → Settings → Security Keys.')
                                            ->helperText('Server-side key for Direct Post API. Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512)
                                            ->columnSpanFull(),
                                        TextInput::make('nmi_public_key')
                                            ->label('Public Key (Collect.js)')
                                            ->hintIcon(Heroicon::InformationCircle, 'Client-side key for Collect.js tokenization. Safe to expose in the browser.')
                                            ->helperText('Client-side key for Collect.js tokenization. Safe to expose in the browser.')
                                            ->maxLength(512)
                                            ->columnSpanFull(),
                                    ]),

                                // ── Authorize.Net credentials ─────────────────────
                                Section::make('Authorize.Net credentials')
                                    ->description('Authorize.Net API credentials.')
                                    ->visible(fn (Get $get): bool => self::isGateway($get, GatewayProvider::AuthorizeNet))
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('authnet_api_login_id')
                                            ->label('API Login ID')
                                            ->hintIcon(Heroicon::InformationCircle, 'Authorize.net API Login ID from the merchant portal.')
                                            ->helperText('Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512),
                                        TextInput::make('authnet_transaction_key')
                                            ->label('Transaction Key')
                                            ->hintIcon(Heroicon::InformationCircle, 'Authorize.net Transaction Key. Rotate via the merchant portal when compromised.')
                                            ->helperText('Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512),
                                        TextInput::make('authnet_public_client_key')
                                            ->label('Public Client Key (Accept.js)')
                                            ->hintIcon(Heroicon::InformationCircle, 'Used for Accept.js tokenization in the browser. Not encrypted — safe to expose client-side.')
                                            ->helperText('Used for Accept.js tokenization in the browser. Not encrypted.')
                                            ->maxLength(512),
                                        TextInput::make('authnet_signature_key')
                                            ->label('Webhook Signature Key')
                                            ->hintIcon(Heroicon::InformationCircle, 'Authorize.net Signature Key for verifying inbound webhook signatures. Stored encrypted.')
                                            ->helperText('HMAC key for verifying inbound Authorize.Net webhooks. Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512),
                                    ]),

                                // ── Stripe credentials ────────────────────────────
                                Section::make('Stripe credentials')
                                    ->description('Stripe API keys from your Stripe Dashboard → Developers → API keys.')
                                    ->visible(fn (Get $get): bool => self::isGateway($get, GatewayProvider::Stripe))
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('stripe_secret_key')
                                            ->label('Secret Key')
                                            ->hintIcon(Heroicon::InformationCircle, 'Stripe Secret Key (sk_live_ or sk_test_). Never expose in the browser. Stored encrypted.')
                                            ->helperText('Starts with sk_live_ or sk_test_. Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512)
                                            ->columnSpanFull(),
                                        TextInput::make('stripe_publishable_key')
                                            ->label('Publishable Key')
                                            ->hintIcon(Heroicon::InformationCircle, 'Stripe Publishable Key (pk_live_ or pk_test_). Safe to expose in browser for Stripe Elements.')
                                            ->helperText('Starts with pk_live_ or pk_test_. Safe to expose in browser (Stripe Elements).')
                                            ->maxLength(512)
                                            ->columnSpanFull(),
                                        TextInput::make('stripe_webhook_secret')
                                            ->label('Webhook Signing Secret')
                                            ->hintIcon(Heroicon::InformationCircle, 'Stripe Webhook Signing Secret (whsec_). Used to verify inbound webhook signatures. Stored encrypted.')
                                            ->helperText('Starts with whsec_. Used to verify inbound Stripe webhook signatures. Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512)
                                            ->columnSpanFull(),
                                    ]),

                                // ── Square credentials ────────────────────────────
                                Section::make('Square credentials')
                                    ->description('Square credentials from your Square Developer Dashboard.')
                                    ->visible(fn (Get $get): bool => self::isGateway($get, GatewayProvider::Square))
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('square_access_token')
                                            ->label('Access Token')
                                            ->hintIcon(Heroicon::InformationCircle, 'Square server-side bearer token for API calls. Production or sandbox. Stored encrypted.')
                                            ->helperText('Production or sandbox bearer token for server-side API calls. Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512)
                                            ->columnSpanFull(),
                                        TextInput::make('square_application_id')
                                            ->label('Application ID')
                                            ->hintIcon(Heroicon::InformationCircle, 'Square Application ID for the Web Payments SDK. Safe to expose client-side.')
                                            ->helperText('Client-side ID for the Square Web Payments SDK. Not encrypted.')
                                            ->maxLength(128),
                                        TextInput::make('square_location_id')
                                            ->label('Location ID')
                                            ->hintIcon(Heroicon::InformationCircle, 'Square Location ID required on Payments and Refunds API calls. Not encrypted.')
                                            ->helperText('Required on Payments / Refunds API calls. Not encrypted.')
                                            ->maxLength(64),
                                        TextInput::make('square_webhook_signature_key')
                                            ->label('Webhook Signature Key')
                                            ->hintIcon(Heroicon::InformationCircle, 'HMAC-SHA256 key for verifying inbound Square webhook payloads. Stored encrypted.')
                                            ->helperText('HMAC-SHA256 key for verifying inbound Square webhook payloads. Stored encrypted.')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(512)
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        // ── Capabilities ──────────────────────────────
                        Tab::make('Capabilities')
                            ->icon(Heroicon::CheckBadge)
                            ->columns(2)
                            ->schema([
                                Toggle::make('allows_recurring_payments')->default(false),
                                Toggle::make('allows_rx_processing')->default(true),
                                Toggle::make('allows_card_present')->default(false),
                                Toggle::make('allows_card_not_present')->default(true),
                                Toggle::make('supports_public_checkout')->default(true),
                                Toggle::make('cim_enabled')
                                    ->label('Enable CIM')
                                    ->helperText('Authorize.Net Customer Information Manager — enables stored payment methods.')
                                    ->visible(fn (Get $get): bool => self::isGateway($get, GatewayProvider::AuthorizeNet)),
                            ]),

                        // ── Routing & surcharge ───────────────────────
                        Tab::make('Routing & surcharge')
                            ->icon(Heroicon::AdjustmentsHorizontal)
                            ->columns(2)
                            ->schema([
                                Section::make('Volume & routing')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('transaction_weight')
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(1)
                                            ->hintIcon(Heroicon::InformationCircle, 'Relative weight for load balancing across accounts. Higher weight = more traffic.')
                                            ->helperText('Higher weight = more traffic when multiple accounts are active.'),
                                        TextInput::make('monthly_volume_limit')
                                            ->numeric()
                                            ->prefix('$')
                                            ->hintIcon(Heroicon::InformationCircle, 'Maximum monthly processing volume in dollars. Leave blank for unlimited.')
                                            ->placeholder('Unlimited'),
                                        Toggle::make('auto_disable_at_limit')
                                            ->label('Auto-disable when monthly limit reached'),
                                        TextInput::make('gateway_endpoint_url')
                                            ->label('Custom Gateway Endpoint URL')
                                            ->url()
                                            ->placeholder('https://secure.nmi.com/api/transact.php')
                                            ->hintIcon(Heroicon::InformationCircle, 'Override the default gateway API endpoint. Leave blank unless using a sandbox proxy or on-premise setup.')
                                            ->helperText('Override the default API endpoint — useful for NMI sandbox proxies or on-premise deployments.')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Surcharge')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->description('Optional surcharge applied to all orders through this account.')
                                    ->components([
                                        TextInput::make('surcharge_rate')
                                            ->label('Rate (%)')
                                            ->numeric()
                                            ->suffix('%')
                                            ->placeholder('e.g. 6.50')
                                            ->hintIcon(Heroicon::InformationCircle, 'Percentage surcharge, e.g. 6.5 = 6.5%.'),
                                        TextInput::make('surcharge_flat_per_txn')
                                            ->label('Flat fee')
                                            ->numeric()
                                            ->prefix('$')
                                            ->placeholder('0.00'),
                                        Toggle::make('surcharge_passthrough')
                                            ->label('Pass through to sales org')
                                            ->helperText('Bill surcharge back to the org at settlement.'),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
