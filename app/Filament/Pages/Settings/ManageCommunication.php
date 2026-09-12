<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Settings\UpdateCommunicationSettingsAction;
use App\Data\Settings\CommunicationSettingsData;
use App\Settings\CommunicationSettings;
use App\Settings\ContactSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @property-read Schema $form
 */
class ManageCommunication extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 55;

    protected static ?string $navigationLabel = 'Communication';

    protected static ?string $title = 'Communication settings';

    protected static ?string $slug = 'settings/communication';

    public function mount(): void
    {
        $this->form->fill(app(CommunicationSettings::class)->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Email')
                    ->description('Which service actually sends mail, and whether it sends at all.')
                    ->columns(2)
                    ->components([
                        Toggle::make('email_enabled')
                            ->label('Send email')
                            ->columnSpanFull()
                            ->helperText('Off means nothing is sent. Pages that would otherwise say "we have emailed you" show honest copy instead — they read what was actually sent, not what was intended.'),

                        Select::make('mail_provider')
                            ->label('Provider')
                            ->options([
                                'mailgun' => 'Mailgun',
                                'postmark' => 'Postmark',
                                'ses' => 'Amazon SES',
                                'smtp' => 'SMTP',
                            ])
                            ->native(false)
                            ->live()
                            ->placeholder('Use the server configuration')
                            ->columnSpanFull()
                            ->helperText('Leave blank to keep using whatever the server is configured with. Choosing one here overrides it without a redeploy.'),

                        TextInput::make('mailgun_domain')
                            ->label('Mailgun sending domain')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('mail_provider') === 'mailgun')
                            ->helperText('The verified domain, e.g. mg.example.com — not your website address.'),
                        TextInput::make('mailgun_secret')
                            ->label('Mailgun API key')
                            ->maxLength(255)
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('mail_provider') === 'mailgun'),
                        TextInput::make('mailgun_endpoint')
                            ->label('Mailgun region endpoint')
                            ->maxLength(255)
                            ->placeholder('api.mailgun.net')
                            ->visible(fn (Get $get): bool => $get('mail_provider') === 'mailgun')
                            ->columnSpanFull()
                            ->helperText('Leave blank for the US region. Use api.eu.mailgun.net for an EU domain — the two stacks are separate and a domain verified in one is rejected by the other.'),

                        TextInput::make('postmark_token')
                            ->label('Postmark server token')
                            ->maxLength(255)
                            ->password()
                            ->revealable()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('mail_provider') === 'postmark'),

                        TextInput::make('ses_key')
                            ->label('AWS access key ID')
                            ->maxLength(255)
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('mail_provider') === 'ses'),
                        TextInput::make('ses_secret')
                            ->label('AWS secret access key')
                            ->maxLength(255)
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('mail_provider') === 'ses'),
                        TextInput::make('ses_region')
                            ->label('AWS region')
                            ->maxLength(64)
                            ->placeholder('us-east-1')
                            ->visible(fn (Get $get): bool => $get('mail_provider') === 'ses')
                            ->columnSpanFull(),

                        TextInput::make('mail_from_address')
                            ->label('From address')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Must be on a domain the provider has verified, or mail is rejected or silently spam-filed. Usually a no-reply address, e.g. no-reply@mg.example.com.'),
                        TextInput::make('mail_from_name')
                            ->label('From name')
                            ->maxLength(255)
                            ->helperText('Defaults to your brand name.'),

                        TextInput::make('mail_reply_to_address')
                            ->label('Reply-to address')
                            ->email()
                            ->maxLength(255)
                            ->placeholder(fn (): ?string => app(ContactSettings::class)->support_email)
                            ->helperText('Where replies go. Any mailbox you read, on any domain — it does not affect deliverability. Leave blank to use the support email from Contact settings.'),
                        TextInput::make('mail_reply_to_name')
                            ->label('Reply-to name')
                            ->maxLength(255)
                            ->helperText('Optional, e.g. "Support".'),
                    ]),

                Section::make('Twilio credentials')
                    ->description('Credentials from your Twilio Console (console.twilio.com). Stored encrypted. Used for SMS, Voice, and proxying telehealth video access tokens.')
                    ->columns(2)
                    ->components([
                        TextInput::make('twilio_account_sid')
                            ->label('Account SID')
                            ->hintIcon(Heroicon::InformationCircle, 'Twilio Account SID — starts with "AC". Found on the Twilio Console dashboard. Stored encrypted.')
                            ->placeholder('ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')
                            ->maxLength(64),
                        TextInput::make('twilio_auth_token')
                            ->label('Auth Token')
                            ->hintIcon(Heroicon::InformationCircle, 'Twilio Auth Token. Found below the Account SID on the Console dashboard. Stored encrypted — treat as a password.')
                            ->password()
                            ->revealable()
                            ->maxLength(64),
                        TextInput::make('twilio_from_number')
                            ->label('From number')
                            ->hintIcon(Heroicon::InformationCircle, 'The Twilio phone number or messaging service SID to send from. E.164 format: +15551234567.')
                            ->placeholder('+15551234567')
                            ->maxLength(20)
                            ->columnSpanFull(),
                    ]),

                Section::make('SMS')
                    ->columns(1)
                    ->components([
                        Toggle::make('sms_enabled')
                            ->label('Enable SMS')
                            ->helperText('Enables lead confirmation texts, appointment reminders, and order status alerts.')
                            ->live(),
                        Textarea::make('sms_opt_in_message')
                            ->label('Double opt-in message')
                            ->hintIcon(Heroicon::InformationCircle, 'Message sent when a patient opts in to SMS. Required for TCPA compliance. Max 500 chars. Leave blank to skip double opt-in.')
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder('Reply YES to confirm you\'d like to receive text updates from us. Msg & data rates may apply. Reply STOP to unsubscribe.')
                            ->visible(fn (Get $get): bool => (bool) $get('sms_enabled')),
                    ]),

                Section::make('Voice')
                    ->components([
                        Toggle::make('voice_enabled')
                            ->label('Enable Voice')
                            ->helperText('Enables outbound voice calls for appointment confirmations and provider callbacks.'),
                    ]),

                Section::make('Video (telehealth consults)')
                    ->description('Patient-facing video consult functionality. Requires Prescribe-Rx to expose the patient video access token endpoint (POST /telehealth/encounters/{id}/video/token). The backend proxies this token to the patient portal — no direct Twilio Video SDK credentials are needed here unless you go fully in-house.')
                    ->components([
                        Toggle::make('video_enabled')
                            ->label('Enable video consults')
                            ->helperText('When enabled, the patient portal shows a "Join Video Consult" button for active encounters. PRX endpoint must be live before enabling.'),
                    ]),

            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            // A field hidden by the provider choice or a toggle is left out of
            // getState(), and the action assigns every field — so without the
            // stored values underneath, switching Mailgun → Postmark erased the
            // Mailgun key and switching SMS off erased the opt-in message.
            // Hidden means "not relevant right now", never "delete".
            $data = CommunicationSettingsData::validateAndCreate(array_merge(
                app(CommunicationSettings::class)->toArray(),
                $this->form->getState(),
            ));
            app(UpdateCommunicationSettingsAction::class)->execute($data);
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
                ->title('Could not save communication settings')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Communication settings saved')
            ->success()
            ->send();
    }
}
