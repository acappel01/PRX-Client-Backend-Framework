<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only authenticated association facts; never a winner or financial state. */
class PaymentTransactionAssociation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['transaction_key', 'evidence_fingerprint', 'facts'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['facts' => 'encrypted:array', 'currency_qualified' => 'boolean', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Association evidence is immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Association evidence is immutable.');
        });
    }
}
