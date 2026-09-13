<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Patient;
use App\Services\PrescribeRx\Client;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('date_of_birth')->date()->placeholder('—'),
                        TextEntry::make('uuid')->label('UUID')->copyable(),
                    ]),

                Section::make('PRX Chart')
                    ->columns(2)
                    ->schema([
                        IconEntry::make('has_prx_chart')
                            ->label('Chart linked')
                            ->boolean()
                            ->getStateUsing(fn ($record) => $record->hasPrxChart()),
                        IconEntry::make('prx_chart_collision_flagged')
                            ->label('Collision flagged')
                            ->boolean()
                            ->trueColor('danger')
                            ->falseColor('success'),
                        TextEntry::make('prx_patient_number')->label('PRX patient number')->placeholder('Recorded on first portal use')->copyable(),
                        TextEntry::make('prx_patient_chart_id')->label('Chart ID')->placeholder('—')->copyable(),
                        TextEntry::make('prx_patient_id')->label('PRX patient id')->placeholder('Recorded on first portal use')->copyable(),
                        TextEntry::make('prx_chart_verified_at')->label('Verified at')->dateTime()->placeholder('—'),
                    ]),

                // Read live from the provider each time the page opens — the
                // provider is the source of truth and nothing here is copied
                // into our database (operator decision 2026-09-13).
                Section::make('As the clinical provider holds it')
                    ->description('Live from the provider. Differences from the account above are expected: an account is our login, the chart is theirs.')
                    ->visible(fn ($record) => $record->hasPrxChart())
                    ->columns(2)
                    ->schema([
                        TextEntry::make('prx_live_name')
                            ->label('Name')
                            ->state(fn ($record) => self::liveChart($record)['name'] ?? null)
                            ->placeholder('Could not load from the provider'),
                        TextEntry::make('prx_live_dob')
                            ->label('Date of birth')
                            ->state(fn ($record) => self::liveChart($record)['dob'] ?? null)
                            ->placeholder('—'),
                        TextEntry::make('prx_live_email')
                            ->label('Email')
                            ->state(fn ($record) => self::liveChart($record)['email'] ?? null)
                            ->placeholder('—'),
                        TextEntry::make('prx_live_phone')
                            ->label('Phone')
                            ->state(fn ($record) => self::liveChart($record)['phone'] ?? null)
                            ->placeholder('—'),
                        TextEntry::make('prx_live_number')
                            ->label('Patient number')
                            ->state(fn ($record) => self::liveChart($record)['patient_number'] ?? null)
                            ->placeholder('—')
                            ->copyable(),
                    ]),

                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('email_verified_at')->label('Email verified')->dateTime()->placeholder('Not verified'),
                        TextEntry::make('two_factor_confirmed_at')
                            ->label('Two-step verification')
                            ->state(fn ($record) => $record->hasTwoFactor() ? $record->two_factor_confirmed_at : null)
                            ->dateTime()
                            ->prefix(fn ($record) => $record->hasTwoFactor() ? 'On since ' : null)
                            ->placeholder('Off'),
                        TextEntry::make('trusted_browsers')
                            ->label('Trusted browsers')
                            ->state(fn ($record) => $record->trustedDevices()->active()->count())
                            ->helperText('Browsers allowed to skip the code. "Sign out everywhere" removes them.'),
                        TextEntry::make('created_at')->label('Registered')->dateTime(),
                        TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                        TextEntry::make('deleted_at')->label('Deleted')->dateTime()->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * One provider read per page render, shared by the entries above. Never
     * throws: an unreachable provider shows placeholders, not an error page.
     *
     * @return array{name?: string, dob?: ?string, email?: ?string, phone?: ?string, patient_number?: ?string}
     */
    private static function liveChart(Patient $patient): array
    {
        // Held in the container, which lives for one request — a static would
        // outlive it and show one render another render's provider data.
        $key = 'patient-infolist.live-chart.'.$patient->getKey();

        if (app()->bound($key)) {
            return app($key);
        }

        $live = rescue(function () use ($patient): array {
            $chart = app(Client::class)->getPatientChart($patient->prx_patient_chart_id);

            return [
                'name' => trim(implode(' ', array_filter([$chart['first_name'] ?? null, $chart['middle_name'] ?? null, $chart['last_name'] ?? null]))) ?: null,
                'dob' => $chart['dob'] ?? null,
                'email' => $chart['email'] ?? null,
                'phone' => $chart['phone'] ?? null,
                'patient_number' => isset($chart['patient_number']) ? (string) $chart['patient_number'] : null,
            ];
        }, [], false);

        app()->instance($key, $live);

        return $live;
    }
}
