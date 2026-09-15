<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable operation effects remain separate from the transaction entity they act upon. */
class PaymentOperationEffectAssociation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['transaction_key', 'parent_transaction_key', 'evidence_fingerprint', 'facts'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['facts' => 'encrypted:array', 'currency_qualified' => 'boolean', 'amount_minor' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Operation effect evidence is immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Operation effect evidence is immutable.');
        });
    }
}
