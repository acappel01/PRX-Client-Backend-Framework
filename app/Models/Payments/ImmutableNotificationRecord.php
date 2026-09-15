<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Raw SQL remains a trusted maintenance boundary; ordinary writers preserve evidence. */
abstract class ImmutableNotificationRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Notification configuration and evidence are immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Notification configuration and evidence are immutable.');
        });
    }
}
