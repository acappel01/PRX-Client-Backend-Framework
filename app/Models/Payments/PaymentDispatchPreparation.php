<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Durable pre-dispatch intent only. Never proof a request was sent, or permission to send/retry it. */
class PaymentDispatchPreparation extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['executor_key', 'request_fingerprint', 'prepared_scope'];

    protected function casts(): array
    {
        return ['prepared_scope' => 'encrypted:array', 'prepared_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Dispatch preparation evidence is immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Dispatch preparation evidence cannot be deleted.');
        });
    }
}
