<?php

namespace App\Http\Resources\Api\V1\Orders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact local commerce history. Expand through the authenticated detail route. */
class OrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status?->value,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'placed_at' => $this->placed_at?->toISOString(),
            'shipped_at' => $this->shipped_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'items_count' => $this->items_count,
        ];
    }
}
