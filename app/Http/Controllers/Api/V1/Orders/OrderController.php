<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Orders\OrderResource;
use App\Models\Commerce\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/orders/{uuid}
 *
 * Customer-owned commerce detail, authenticated by the existing portal session.
 */
class OrderController extends ApiController
{
    /**
     * Retrieve an order by UUID.
     *
     * Returns items, shipments, and fulfillment center only for the authenticated
     * portal account’s active Customer. Missing, unowned, and conflicting legacy
     * orders share the same 404 response. Requires a valid portal Sanctum session.
     *
     * @tags Orders
     */
    public function show(Request $request, string $uuid): OrderResource|JsonResponse
    {
        $accountId = $request->user()->getKey();

        $order = Order::where('uuid', $uuid)
            ->whereHas('customer', fn ($query) => $query->where('portal_account_id', $accountId))
            // A conflicting legacy account is not evidence we may ignore.
            ->where(fn ($query) => $query->whereNull('patient_id')->orWhere('patient_id', $accountId))
            ->with(['items', 'shipments', 'fulfillmentCenter'])
            ->first();

        if (! $order) {
            return $this->error('Order not found.', 404);
        }

        return new OrderResource($order);
    }
}
