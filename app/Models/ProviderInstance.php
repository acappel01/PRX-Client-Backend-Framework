<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Stable provider/tenant/environment namespace; contains no credentials. */
class ProviderInstance extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['account_type' => 'tenant'];

    protected $hidden = ['external_account_id'];

    protected static function booted(): void
    {
        static::updating(function (ProviderInstance $instance): void {
            if ($instance->isDirty(['key', 'provider', 'environment', 'account_type', 'external_account_id'])) {
                throw ValidationException::withMessages([
                    'provider_instance' => 'A provider namespace cannot be changed. Register a separate instance.',
                ]);
            }
        });
    }
}
