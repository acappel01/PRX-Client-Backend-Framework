<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Reserved correlation identity, never dispatch permission or proof of execution. */
class PaymentOperationReference extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payment_operation_id', 'gateway_account_binding_id', 'reference'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Payment references are immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Payment references cannot be deleted.');
        });
    }
}
