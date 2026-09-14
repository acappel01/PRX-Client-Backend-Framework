<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;

/** Durable local purchase identity; uncertain outcomes require reconciliation. */
class CheckoutAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'result', 'provider_receipt', 'lead_id', 'cart_id', 'order_id', 'provider_idempotency_key',
        'request_fingerprint', 'answers_fingerprint', 'cart_fingerprint',
        'provider_encounter_type_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (CheckoutAttempt $attempt): void {
            $mutable = ['status', 'result', 'provider_receipt', 'completed_at', 'updated_at'];
            if ($attempt->isDirty('provider_receipt') && $attempt->getRawOriginal('provider_receipt') !== null) {
                throw new \LogicException('Checkout provider receipt is immutable.');
            }
            if (array_diff(array_keys($attempt->getDirty()), $mutable) !== []) {
                throw new \LogicException('Checkout attempt identity is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'result' => 'encrypted:array',
            'provider_receipt' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
