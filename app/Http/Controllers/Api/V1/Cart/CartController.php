<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Cart\CartResource;
use App\Http\Resources\Api\V1\Catalog\CatalogRelationItemResource;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Commerce\Cart;
use App\Models\Commerce\CartItem;
use App\Models\Commerce\CheckoutAttempt;
use App\Settings\BillingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cart endpoints — token-identified via X-Cart-Token header.
 * No authentication required.
 *
 * GET    /api/v1/cart
 * POST   /api/v1/cart/items
 * PATCH  /api/v1/cart/items/{cartItem}
 * DELETE /api/v1/cart/items/{cartItem}
 * DELETE /api/v1/cart
 */
class CartController extends ApiController
{
    /**
     * Resolve the locked logical cart, following persisted successors.
     * Completed carts rotate; expired tokens cannot follow successors.
     * Uncertain attempts never rotate, and expired uncertain tokens fail closed.
     * Callers hold a transaction through their cart read or mutation.
     */
    private function resolveCart(Request $request): Cart
    {
        $token = $request->header('X-Cart-Token');

        if ($token) {
            $cart = Cart::where('ulid', $token)->lockForUpdate()->first();
            while ($cart !== null) {
                $attempt = CheckoutAttempt::where('cart_id', $cart->id)->lockForUpdate()->first();
                if ($cart->expires_at !== null && ! $cart->expires_at->isFuture()) {
                    abort_if($attempt !== null && $attempt->status !== 'completed', 409,
                        'This order is awaiting confirmation. Please contact support.');
                    // An expired bearer token must never reveal its live successor.
                    break;
                }
                if ($attempt !== null && $attempt->status !== 'completed') {
                    return $cart;
                }
                if ($cart->successor_cart_id !== null) {
                    $cart = Cart::query()->lockForUpdate()->findOrFail($cart->successor_cart_id);

                    continue;
                }
                if ($attempt?->status === 'completed') {
                    $successor = Cart::create([
                        'email' => $cart->email,
                        'coupon_code' => $cart->coupon_code,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                    // Finalization preserves edits made during the network call.
                    // Move those exact rows so old item identifiers remain usable.
                    $cart->items()->update(['cart_id' => $successor->id]);
                    $cart->forceFill(['successor_cart_id' => $successor->id])->save();

                    return $successor;
                }

                return $cart;
            }
        }

        return Cart::create([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Get the current cart.
     *
     * Returns the cart identified by the X-Cart-Token header, creating a new one if the
     * token is absent or expired. The response always includes the cart token so the
     * frontend can persist it for subsequent requests.
     *
     * @tags Cart
     *
     * @unauthenticated
     */
    public function show(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $cart = $this->resolveCart($request);
            $cart->load(['items.itemable', 'items.plan']);

            return $this->success((new CartResource($cart))->toArray($request));
        });
    }

    /**
     * Get upsell suggestions for the current cart.
     *
     * Returns catalog items related to what is already in the cart, driven by the
     * admin-curated "Pairs With" and "Related" catalog relations. Pairs-with items
     * are preferred; related items fill any remaining slots. Items already in the
     * cart are excluded. Returns an empty list when upsells are disabled in the
     * admin billing settings or the cart is empty.
     *
     * @tags Cart
     *
     * @unauthenticated
     */
    public function suggestions(Request $request, BillingSettings $billing): JsonResponse
    {
        return DB::transaction(function () use ($request, $billing): JsonResponse {
            if (! $billing->upsells_enabled) {
                return $this->success([]);
            }

            $cart = $this->resolveCart($request);
            $cart->load('items.itemable');

            $itemables = $cart->items
                ->map(fn (CartItem $item) => $item->itemable)
                ->filter();

            $inCart = $itemables
                ->map(fn ($itemable) => $itemable::class.':'.$itemable->id)
                ->all();

            $suggestions = collect();

            // Pairs-with suggestions first (curated companions), then related
            // items to fill remaining slots. Dedupe across both passes and
            // exclude anything already in the cart.
            foreach (['pairsWithItems', 'relatedItems'] as $method) {
                foreach ($itemables as $itemable) {
                    foreach ($itemable->{$method}() as $target) {
                        $key = $target::class.':'.$target->id;

                        if (in_array($key, $inCart, true) || $suggestions->has($key)) {
                            continue;
                        }

                        $suggestions->put($key, $target);
                    }
                }
            }

            $suggestions = $suggestions->values()->take($billing->upsells_limit);

            return $this->success(
                CatalogRelationItemResource::collection($suggestions)->toArray($request)
            );
        });
    }

    /**
     * Add an item to the cart.
     *
     * Adds a product or package to the cart, optionally under one of its plans. Increments
     * quantity if the same item and plan already exist in the cart. Requires `type` and `id`;
     * `plan_id` is OPTIONAL for both products and packages — omit it to buy the item itself
     * once at its own price, or give it to subscribe under that plan. The plan must belong to
     * the item. Returns the updated cart.
     *
     * @tags Cart
     *
     * @unauthenticated
     */
    public function addItem(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $validated = $request->validate([
                'type' => ['required', 'in:product,package'],
                'id' => ['required', 'integer'],
                // OPTIONAL FOR PACKAGES TOO. This was
                // `requiredIf(type === 'package')`, which made a package
                // purchasable only as a subscription — there was no way to buy the
                // stack itself. A package IS a product, or a group of them, with its
                // own price; plans are the separate recurring offer alongside it.
                //
                // The buy-once branch below needed no change to suit this: it
                // already read the item's own `sale_price ?? retail_price`, and was
                // simply unreachable for packages while this rule stood.
                'plan_id' => ['nullable', 'integer'],
                'quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            ]);

            $cart = $this->resolveCart($request);

            $itemableClass = $validated['type'] === 'product'
                ? Product::class
                : Package::class;

            $itemable = $itemableClass::findOrFail($validated['id']);

            // Resolve the price snapshot from the plan when one is given (package
            // plans and product term plans), else from the item's own buy-once
            // price. A plan must belong to the item it is being added under.
            $price = $itemable->sale_price ?? $itemable->retail_price;

            if (! empty($validated['plan_id'])) {
                $plan = Plan::findOrFail($validated['plan_id']);
                $ownerKey = $validated['type'] === 'package' ? 'package_id' : 'product_id';

                abort_if($plan->{$ownerKey} !== $itemable->id, 422, 'The selected plan does not belong to this item.');

                $price = $plan->sale_price ?? $plan->retail_price;
            }

            // Increment quantity if the same item+plan combination is already in the cart.
            $existing = $cart->items()
                ->where('itemable_type', $itemableClass)
                ->where('itemable_id', $validated['id'])
                ->where('plan_id', $validated['plan_id'] ?? null)
                ->first();

            if ($existing) {
                $existing->increment('quantity', $validated['quantity'] ?? 1);
            } else {
                $cart->items()->create([
                    'itemable_type' => $itemableClass,
                    'itemable_id' => $validated['id'],
                    'plan_id' => $validated['plan_id'] ?? null,
                    'quantity' => $validated['quantity'] ?? 1,
                    'unit_price_snapshot' => $price,
                ]);
            }

            $cart->load(['items.itemable', 'items.plan']);

            return $this->success((new CartResource($cart))->toArray($request), status: 201);
        });
    }

    /**
     * Update a cart item's quantity.
     *
     * Sets the quantity of the specified cart item. Passing `quantity=0` removes the item
     * from the cart entirely. Returns the updated cart state.
     *
     * @tags Cart
     *
     * @unauthenticated
     */
    public function updateItem(Request $request, CartItem $cartItem): JsonResponse
    {
        return DB::transaction(function () use ($request, $cartItem): JsonResponse {
            $cart = $this->resolveCart($request);

            $cartItem->refresh();
            abort_if($cartItem->cart_id !== $cart->id, 403, 'This item does not belong to your cart.');

            $validated = $request->validate([
                'quantity' => ['required', 'integer', 'min:0', 'max:10'],
            ]);

            if ($validated['quantity'] === 0) {
                $cartItem->delete();
            } else {
                $cartItem->update(['quantity' => $validated['quantity']]);
            }

            $cart->load(['items.itemable', 'items.plan']);

            return $this->success((new CartResource($cart))->toArray($request));
        });
    }

    /**
     * Remove a single item from the cart.
     *
     * Deletes the specified cart item and returns the updated cart.
     *
     * @tags Cart
     *
     * @unauthenticated
     */
    public function removeItem(Request $request, CartItem $cartItem): JsonResponse
    {
        return DB::transaction(function () use ($request, $cartItem): JsonResponse {
            $cart = $this->resolveCart($request);

            $cartItem->refresh();
            abort_if($cartItem->cart_id !== $cart->id, 403, 'This item does not belong to your cart.');

            $cartItem->delete();
            $cart->load(['items.itemable', 'items.plan']);

            return $this->success((new CartResource($cart))->toArray($request));
        });
    }

    /**
     * Clear all items from the cart.
     *
     * Removes every item from the cart identified by X-Cart-Token. Returns the now-empty cart.
     *
     * @tags Cart
     *
     * @unauthenticated
     */
    public function clear(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $cart = $this->resolveCart($request);
            $cart->items()->delete();
            $cart->load(['items.itemable', 'items.plan']);

            return $this->success((new CartResource($cart))->toArray($request));
        });
    }
}
