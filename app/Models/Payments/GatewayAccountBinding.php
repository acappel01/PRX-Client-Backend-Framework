<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A read-qualified local credential binding, not remote PRX ownership or payment readiness. */
class GatewayAccountBinding extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['merchant_fingerprint', 'provider_mapping', 'gateway_account_id'];

    protected function casts(): array
    {
        return ['provider_mapping' => 'encrypted', 'verified_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Gateway bindings cannot be changed.');
        });
        static::deleting(function (): void {
            throw new LogicException('Gateway bindings cannot be deleted.');
        });
    }
}
