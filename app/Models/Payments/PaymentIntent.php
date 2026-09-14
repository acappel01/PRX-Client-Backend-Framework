<?php

namespace App\Models\Payments;

use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable commercial obligation; it never projects paid revenue. */
class PaymentIntent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['order_id', 'customer_id', 'merchant_account_id', 'merchant_account_uuid', 'order_uuid', 'customer_uuid', 'request_fingerprint', 'merchant_binding_fingerprint', 'order_snapshot_fingerprint', 'executor_key'];

    protected function casts(): array
    {
        return [
            'gateway_provider' => GatewayProvider::class,
            'environment' => GatewayEnvironment::class,
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Payment intent snapshots cannot be changed.');
        });
        static::deleting(function (): void {
            throw new LogicException('Payment intent history cannot be deleted.');
        });
    }
}
