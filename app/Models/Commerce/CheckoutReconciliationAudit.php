<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;

/** Append-only local repair history; no provider receipt or clinical payload. */
class CheckoutReconciliationAudit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['checkout_attempt_id', 'provider_instance_id', 'reason'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Checkout reconciliation history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Checkout reconciliation history is immutable.'));
    }
}
