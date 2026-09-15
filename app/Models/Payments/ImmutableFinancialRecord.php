<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class ImmutableFinancialRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Financial reporting evidence is immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Financial reporting evidence is immutable.');
        });
    }
}
