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

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'prx_chart_verified_at' => 'datetime',
            'prx_chart_collision_flagged' => 'boolean',
            'email_verified_at' => 'datetime',
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
}
