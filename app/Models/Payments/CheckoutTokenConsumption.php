<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CheckoutTokenConsumption extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['consumed_at' => 'immutable_datetime'];
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
