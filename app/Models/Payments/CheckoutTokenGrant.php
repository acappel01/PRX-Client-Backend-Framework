<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CheckoutTokenGrant extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['scope', 'scope_fingerprint', 'token_fingerprint'];

    protected function casts(): array
    {
        return ['scope' => 'encrypted:array', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new LogicException('Checkout authorization evidence is immutable.');
        });
        self::deleting(function (): void {
            throw new LogicException('Checkout authorization evidence is immutable.');
        });
    }
}
