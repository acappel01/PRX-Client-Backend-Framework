<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Orders\OrderResource;
use App\Http\Resources\Api\V1\Orders\OrderSummaryResource;
use App\Models\Commerce\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/orders
 * GET /api/v1/orders/{uuid}
 *
 * Customer-owned commerce detail, authenticated by the existing portal session.
 */
class OrderController extends ApiController
{
    /**
     * List the authenticated Customer's local orders.
     *
     * Newest placed orders first, with the local primary key breaking timestamp
     * ties. Page is bounded to 1–10000; per_page is 1–100, default 20. This reads
     * local commerce summaries and makes no provider request.
     *
     * @tags Orders
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $this->ownedOrders($request)
            ->select(['id', 'uuid', 'status', 'total_amount', 'currency', 'placed_at',
                'shipped_at', 'delivered_at', 'cancelled_at'])
            ->withCount('items')
            ->orderByDesc('placed_at')->orderByDesc('id')
            ->paginate((int) ($pagination['per_page'] ?? 20), ['*'], 'page', (int) ($pagination['page'] ?? 1))
            ->appends($pagination);

        return OrderSummaryResource::collection($orders);
    }

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
        $order = $this->ownedOrders($request)->where('uuid', $uuid)
            ->with(['items', 'shipments', 'fulfillmentCenter'])
            ->first();

        if (! $order) {
            return $this->error('Order not found.', 404);
        }

        return new OrderResource($order);
    }

    /** The one ownership boundary for commerce history and detail. */
    private function ownedOrders(Request $request): Builder
    {
        $accountId = $request->user()->getKey();

        return Order::query()
            ->whereHas('customer', fn ($query) => $query->where('portal_account_id', $accountId))
            // A conflicting legacy account is not evidence we may ignore.
            ->where(fn ($query) => $query->whereNull('patient_id')->orWhere('patient_id', $accountId));
    }
}
