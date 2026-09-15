<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Claimed may include a crash before or during transport; never re-dispatch it. */
class PaymentDispatchAttempt extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['executor_key', 'request_fingerprint', 'request_facts', 'receipt'];

    protected function casts(): array
    {
        return ['request_facts' => 'encrypted:array', 'receipt' => 'encrypted:array', 'claimed_at' => 'immutable_datetime', 'transport_started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->getRawOriginal('status') !== 'claimed' || ! in_array($row->status, ['response_observed', 'outcome_unknown'], true)
             || array_diff(array_keys($row->getDirty()), ['status', 'receipt', 'transport_started_at', 'completed_at']) !== []
             || $row->completed_at === null || $row->transport_started_at === null) {
                throw new LogicException('Dispatch provenance cannot be replaced.');
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Dispatch provenance cannot be deleted.');
        });
    }
}
