<?php

namespace App\Models\Payments;

use App\Enums\Payments\PaymentOperationPurpose;
use App\Enums\Payments\PaymentOperationState;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable identity with one monotonic prepared-to-uncertain transition. */
class PaymentOperation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['executor_key', 'payment_intent_id', 'original_operation_id', 'request_fingerprint', 'uncertainty_fingerprint', 'uncertainty_evidence'];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentOperationPurpose::class,
            'state' => PaymentOperationState::class,
            'amount_minor' => 'integer',
            'uncertainty_evidence' => 'encrypted:array',
            'created_at' => 'immutable_datetime',
            'uncertain_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $operation): void {
            if (array_diff(array_keys($operation->getDirty()), ['state', 'uncertain_at', 'uncertainty_fingerprint', 'uncertainty_evidence']) !== []
                || $operation->getRawOriginal('state') !== PaymentOperationState::Prepared->value
                || $operation->state !== PaymentOperationState::Uncertain
                || $operation->uncertain_at === null || $operation->uncertainty_evidence === null
                || $operation->uncertainty_fingerprint === null) {
                throw new LogicException('Payment operation identity and uncertainty evidence are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Payment operation history cannot be deleted.');
        });
    }
}
