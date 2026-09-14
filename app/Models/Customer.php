<?php

namespace App\Models;

use App\Models\Commerce\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A commerce customer; an optional portal account is a separate authentication identity. */
class Customer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'date_of_birth',
        'provider_environment', 'prx_patient_chart_id', 'prx_patient_id', 'prx_patient_number',
    ];

    // Explicit resources/Filament fields may read these. Generic serialization must not
    // copy contact data into workflow jobs, logs or a future public response.
    protected $hidden = [
        'first_name', 'last_name', 'email', 'phone', 'date_of_birth',
        'portal_account_id', 'provider_environment', 'prx_patient_chart_id', 'prx_patient_id', 'prx_patient_number',
    ];

    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted', 'last_name' => 'encrypted',
            'email' => 'encrypted', 'phone' => 'encrypted', 'date_of_birth' => 'encrypted',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'portal_account_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
