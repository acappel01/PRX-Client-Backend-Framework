<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One two-step verification recovery code, stored as sha256 only. See TwoFactor. */
class PatientRecoveryCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['patient_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
