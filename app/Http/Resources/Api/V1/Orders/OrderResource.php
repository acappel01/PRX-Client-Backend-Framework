<?php

namespace App\Http\Resources\Api\V1\Orders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/orders/{uuid}
 *
 * The controller enforces active Customer ownership. Shipping and billing
 * addresses remain deliberately omitted from this response.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status?->value,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'shipping_amount' => $this->shipping_amount,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency ?? 'USD',
            'placed_at' => $this->placed_at?->toISOString(),
            'shipped_at' => $this->shipped_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'prescribe_rx_order_number' => $this->prescribe_rx_order_number,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'shipments' => OrderShipmentResource::collection($this->whenLoaded('shipments')),
            'fulfillment_center' => $this->when(
                $this->relationLoaded('fulfillmentCenter') && $this->fulfillmentCenter,
                fn () => [
                    'name' => $this->fulfillmentCenter->name,
                    'system_type' => $this->fulfillmentCenter->system_type->value,
                ]
            ),
        ];
    }
}
