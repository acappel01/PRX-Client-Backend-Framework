<?php

namespace App\Models\Commerce;

use App\Models\ProviderInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durable local purchase identity; uncertain outcomes require reconciliation. */
class CheckoutAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'result', 'provider_receipt', 'lead_id', 'cart_id', 'order_id', 'provider_idempotency_key',
        'request_fingerprint', 'answers_fingerprint', 'cart_fingerprint',
        'provider_encounter_type_id', 'provider_instance_id', 'provider_client_id',
        'provider_sales_org_id', 'provider_tenant_kind', 'order_fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(function (CheckoutAttempt $attempt): void {
            $mutable = ['status', 'result', 'receipt_received_at', 'provider_receipt', 'completed_at', 'updated_at'];
            if ($attempt->isDirty('receipt_received_at') && $attempt->getRawOriginal('receipt_received_at') !== null) {
                throw new \LogicException('Checkout receipt time is immutable.');
            }
            if ($attempt->isDirty('provider_receipt') && $attempt->getRawOriginal('provider_receipt') !== null) {
                throw new \LogicException('Checkout provider receipt is immutable.');
            }
            if (array_diff(array_keys($attempt->getDirty()), $mutable) !== []) {
                throw new \LogicException('Checkout attempt identity is immutable.');
            }
        });
    }

    public function providerInstance(): BelongsTo
    {
        return $this->belongsTo(ProviderInstance::class);
    }

    protected function casts(): array
    {
        return [
            'result' => 'encrypted:array',
            'provider_receipt' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'receipt_received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
