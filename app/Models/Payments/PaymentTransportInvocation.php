<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Exclusive adapter entry claim, not proof of network delivery. */
class PaymentTransportInvocation extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['claimed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Transport claims are immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Transport claims are immutable.');
        });
    }
}
