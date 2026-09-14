<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only unverified evidence; deliberately has no verified/resolved state. */
class PaymentOutcomeObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payment_operation_id', 'request_fingerprint', 'reported_evidence'];

    protected function casts(): array
    {
        return ['reported_evidence' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Payment outcome observations cannot be changed.');
        });
        static::deleting(function (): void {
            throw new LogicException('Payment outcome observations cannot be deleted.');
        });
    }
}
