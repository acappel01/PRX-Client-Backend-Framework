<?php

namespace App\Data\Patient;

use App\Enums\Patient\TwoFactorPolicy;
use App\Models\Patient;
use App\Settings\PortalSettings;
use Spatie\LaravelData\Data;

class PatientResource extends Data
{
    public function __construct(
        public string $uuid,
        public string $email,
        public string $first_name,
        public string $last_name,
        public ?string $phone,
        public ?string $date_of_birth,
        public bool $has_prx_chart,
        public bool $email_verified,
        public bool $prx_chart_collision_flagged,
        public string $created_at,
        /** @var array{enabled: bool, setup_required: bool} */
        public array $two_factor = ['enabled' => false, 'setup_required' => false],
    ) {}

    public static function fromModel(Patient $patient): self
    {
        return new self(
            uuid: $patient->uuid,
            email: $patient->email,
            first_name: $patient->first_name,
            last_name: $patient->last_name,
            phone: $patient->phone,
            date_of_birth: $patient->date_of_birth?->toDateString(),
            has_prx_chart: $patient->hasPrxChart(),
            email_verified: $patient->email_verified_at !== null,
            prx_chart_collision_flagged: $patient->prx_chart_collision_flagged,
            created_at: $patient->created_at->toIso8601String(),
            two_factor: [
                'enabled' => $patient->hasTwoFactor(),
                // Lets the portal land a just-created or just-signed-in session
                // on setup instead of a screen that would answer 403.
                'setup_required' => ! $patient->hasTwoFactor()
                    && app(PortalSettings::class)->twoFactorPolicy() === TwoFactorPolicy::Required,
            ],
        );
    }
}
