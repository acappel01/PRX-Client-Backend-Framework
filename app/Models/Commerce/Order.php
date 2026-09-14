<?php

namespace App\Models\Commerce;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'encounter_id',
        'customer_id',
        'patient_id',
        'fulfillment_center_id',
        'prescribe_rx_order_id',
        'prescribe_rx_order_number',
        'status',
        'provider_workflow_status',
        'provider_payment_status',
        'provider_shipping_status',
        'provider_status_at',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'total_amount',
        'currency',
        'shipping_address',
        'billing_address',
        'placed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'refunded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            // Encrypted snapshots — these can include PII paired with item
            // names to imply prescription info, so we encrypt at the model
            // level on top of RDS at-rest encryption.
            'shipping_address' => 'encrypted:array',
            'billing_address' => 'encrypted:array',
            'provider_status_at' => 'datetime',
            'placed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Legacy portal-account association; customer ownership is separate. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function fulfillmentCenter(): BelongsTo
    {
        return $this->belongsTo(FulfillmentCenter::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
