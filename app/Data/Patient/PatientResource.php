<?php

namespace App\Data\Patient;

use App\Models\Patient;
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
        );
    }
}
