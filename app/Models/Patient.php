<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Patient extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'phone',
        'date_of_birth',
        'prx_patient_chart_id',
        'prx_patient_id',
        'prx_chart_verified_at',
        'prx_chart_collision_flagged',
        'email_verified_at',
    ];

    /**
     * Every two-factor column is hidden, not for tidiness: workflow payloads copy
     * the visible attributes into the queue (RunWorkflowChain), so a column
     * missing from here is a secret in Redis, `failed_jobs` and Horizon.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_pending_secret',
        'two_factor_pending_at',
        'two_factor_confirmed_at',
        'two_factor_last_timestep',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'prx_chart_verified_at' => 'datetime',
            'prx_chart_collision_flagged' => 'boolean',
            'email_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_pending_secret' => 'encrypted',
            'two_factor_pending_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    public function hasPrxChart(): bool
    {
        return filled($this->prx_patient_chart_id);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(PatientSecurityEvent::class);
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(PatientRecoveryCode::class);
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(PatientTrustedDevice::class);
    }

    public function authChallenges(): HasMany
    {
        return $this->hasMany(PatientAuthChallenge::class);
    }
}
