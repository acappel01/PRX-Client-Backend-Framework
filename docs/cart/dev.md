# Cart Module — Developer Guide

**Status:** Shipped 2026-06-28

---

## Data model

```
carts
  └── hasMany → cart_items
                  └── morphTo → itemable (Product | Package)
                  └── belongsTo → plans
```

### `carts`

| Column | Type | Notes |
|---|---|---|
| `ulid` | `string(26)` | Unique token. Sent to clients as the cart identifier. Auto-generated on create via model boot. |
| `user_id` | `bigint` nullable | FK to `users`. Reserved for authenticated user carts — not populated by current flows. |
| `email` | `string` nullable | Reserved field. Not populated by the cart API itself; intended for future guest checkout association. |
| `coupon_code` | `string` nullable | Reserved for future coupon/discount functionality. |
| `ip_address` | `string(45)` nullable | Captured on cart creation. Supports IPv6. |
| `user_agent` | `string(512)` nullable | Captured on cart creation. |
| `expires_at` | `timestamp` nullable | Defaults to `now() + 30 days` on create. Null = never expires. |

### `cart_items`

| Column | Type | Notes |
|---|---|---|
| `cart_id` | `bigint` | FK to `carts`. Cascade delete. |
| `itemable_type` | `string` | Polymorphic type: `App\Models\Catalog\Product` or `App\Models\Catalog\Package`. |
| `itemable_id` | `bigint` | Polymorphic ID. |
| `plan_id` | `bigint` nullable | FK to `plans`. **Optional for both** — null means the item itself was bought at its own price, with no plan and no rebill. |
| `quantity` | `smallint` | 1–10 enforced by API validation. |
| `unit_price_snapshot` | `decimal(10,2)` nullable | Price at add-to-cart time (sale_price ?? retail_price). Frozen — not updated when catalog prices change. |

### `leads` (related column)

| Column | Notes |
|---|---|
| `cart_ulid` | Added by migration `2026_06_27_194409`. Captured from `X-Cart-Token` header when a lead is created. Used at checkout to verify cart/lead session pairing. |

---

## Models

### `App\Models\Commerce\Cart`

- Auto-generates `ulid` and sets `expires_at = now() + 30 days` in the `booted()` hook.
- `items()` — `HasMany` to `CartItem`.
- `itemCount(): int` — sum of all item quantities.
- `subtotal(): float` — sum of `unit_price_snapshot * quantity` for items with a non-null snapshot.
- `isEmpty(): bool` — true if no items exist.

### `App\Models\Commerce\CartItem`

- `itemable()` — `MorphTo` → `Product` or `Package`.
- `plan()` — `BelongsTo` → `Plan`.
- `lineTotal(): float` — `unit_price_snapshot * quantity`, returns 0.0 if snapshot is null.
- `unit_price_snapshot` is cast to `decimal:2`.

---

## Services

### `App\Services\Cart\CartService`

Session-backed cart store. Used by internal/Livewire admin flows; **not** used by the public REST API (`CartController` operates directly on the database).

| Method | Purpose |
|---|---|
| `view(): CartViewData` | Hydrates the stored session rows into a full `CartViewData` DTO. Eager-loads all referenced models in 3 grouped queries. Items whose underlying model was deleted or unpublished are returned with `available = false`. |
| `add(type, id, qty)` | Add or increment an item. Max quantity per line: 99. |
| `setQuantity(type, id, qty)` | Set absolute quantity. Calls `remove()` if qty < 1. |
| `increment(type, id)` | Convenience wrapper for `add(..., 1)`. |
| `decrement(type, id)` | Decrements by 1; removes item if result would be < 1. |
| `remove(type, id)` | Remove all units of an item. |
| `clear()` | Wipe the entire session cart. |
| `totalQuantity(): int` | Sum of all quantities in session. |
| `snapshot(): array` | Raw stored rows — used to serialize into `Lead.cart_items`. |

Session key: `cart`. Stored shape per row: `{resource_type: string, resource_id: int, quantity: int}`.

Allowed `resource_type` values: `product`, `package`, `plan`.

The service's PHPDoc notes a planned swap point: when a logged-in patient store is added, this service can read from the `cart_items` DB table instead of session without touching the Livewire layer.

---

## DTOs

### `App\Data\Cart\CartViewData`

Output DTO from `CartService::view()`.

| Field | Type |
|---|---|
| `items` | `DataCollection<CartItemViewData>` |
| `total_quantity` | `int` |
| `subtotal` | `float` |
| `has_unavailable_items` | `bool` |

### `App\Data\Cart\CartItemViewData`

One line item, hydrated from the catalog model.

| Field | Notes |
|---|---|
| `resource_type` | `product\|package\|plan` |
| `resource_id` | Catalog model ID |
| `name`, `slug`, `url` | Resolved from model |
| `hero_image_path` | Nullable |
| `unit_price` | `sale_price ?? retail_price` — null if not set |
| `line_total` | `unit_price * quantity` — null if price not set |
| `price_suffix` | From model's `price_suffix` field, or billing period suffix for plans |
| `billing_period` | Plan's billing period value; null for products/packages |
| `prescribe_rx_id` | Provider ID for intake submission |
| `prescribe_rx_number` | Provider product number |
| `available` | `false` if the underlying catalog record no longer exists |

### `App\Data\Leads\CartItemData`

Used when snapshotting cart contents into a `Lead` record (stored as JSON in `leads.cart_items`).

---

## API endpoints

All cart endpoints are **unauthenticated** (no Sanctum token required). Cart identity is established via the `X-Cart-Token` request header.

**Token resolution behavior:** If `X-Cart-Token` is absent, empty, or points to an expired cart, a new cart is created and returned. The frontend must persist the token from each response.

---

### `GET /api/v1/cart`

Resolve or create the current cart.

**Headers:** `X-Cart-Token: <ulid>` (optional)

**Response `200`:**
```json
{
  "data": {
    "token": "01hwzxyz...",
    "email": null,
    "coupon_code": null,
    "item_count": 2,
    "subtotal": 298.00,
    "expires_at": "2026-07-28T14:00:00.000000Z",
    "items": [
      {
        "id": 1,
        "type": "Product",
        "quantity": 2,
        "unit_price": "149.00",
        "line_total": 298.0,
        "item": { /* ProductResource */ },
        "plan": null
      }
    ]
  }
}
```

---

### `POST /api/v1/cart/items`

Add an item to the cart. Increments quantity if the same item+plan combination already exists.

**Headers:** `X-Cart-Token: <ulid>` (optional — creates cart if absent)

**Request body:**

| Field | Type | Rules |
|---|---|---|
| `type` | string | Required. `product` or `package`. |
| `id` | integer | Required. The catalog model ID. |
| `plan_id` | integer | **Optional, for packages as well as products.** Omit it to buy the item once at its own price; give it to subscribe under that plan. The plan must belong to the item. |
| `quantity` | integer | Optional. 1–10. Defaults to 1. |

**Response `201`:** Full cart resource (same shape as `GET /api/v1/cart`).

**Price resolution:** with a `plan_id`, the plan's `sale_price ?? retail_price` is snapshotted;
without one, the item's own `sale_price ?? retail_price` is — the same rule for products and
packages.

**Omitting `plan_id` for a package is the primary purchase path, not an edge case.** A package
is a set group of products bought once, and its plans are a separate recurring commitment over
the same bundle; `price_from.plan_id` on the catalogue payload tells a card which of the two its
figure came from, and a card that adds to the cart must pass that value through — omitting the
key entirely when it is null. Sending a `plan_id` a card did not quote enrols the buyer in a
rebill they did not choose. `plan_id` was `required_if:type,package` until `a464b0a`, which made
a package purchasable only as a subscription; see `CartController::addItem`.

---

### `PATCH /api/v1/cart/items/{cartItem}`

Update a cart item's quantity. Passing `quantity = 0` removes the item entirely.

**Headers:** `X-Cart-Token: <ulid>` (optional)

**Request body:**

| Field | Type | Rules |
|---|---|---|
| `quantity` | integer | Required. 0–10. 0 removes the item. |

**Response `200`:** Full cart resource.

---

### `DELETE /api/v1/cart/items/{cartItem}`

Remove a single item from the cart.

**Headers:** `X-Cart-Token: <ulid>` (optional)

**Response `200`:** Full cart resource with the item removed.

---

### `DELETE /api/v1/cart`

Clear all items from the cart. The cart record itself is not deleted; only its items are removed.

**Headers:** `X-Cart-Token: <ulid>` (optional — creates a new empty cart if absent)

**Response `200`:** Full cart resource with `item_count: 0` and `subtotal: 0`.

---

### `GET /api/v1/cart/suggestions`

Upsell / cross-sell suggestions for the current cart, driven by the admin-curated
**Pairs With** / **Related** catalog relations of the items in the cart. Nothing
is hardcoded — the admin curates relations on each product/package.

**Headers:** `X-Cart-Token: <ulid>` (optional — an absent/expired token yields an empty cart and therefore no suggestions)

**Resolution rules (server-side):**

1. Pairs-with targets of every cart item are collected first, then related targets fill remaining slots.
2. Items already in the cart are excluded; duplicates across sources are removed.
3. Unpublished (draft/archived) targets are filtered out.
4. The list is capped at `BillingSettings::$upsells_limit` (admin: Settings → Billing).
5. Returns `[]` when `BillingSettings::$upsells_enabled` is off — frontends can simply hide the placement when the list is empty.

**Response `200`:** array of `CatalogRelationItemResource` light cards:

```json
{
  "data": [
    {
      "type": "product",
      "id": 9,
      "name": "Sleep Stack",
      "slug": "sleep-stack",
      "subtitle": null,
      "badge_text": null,
      "hero_image_url": "https://…/storage/sections/….png",
      "is_in_stock": true,
      "price": { "retail": 199, "sale": null, "effective": 199, "suffix": null, "currency": "USD" }
    }
  ]
}
```

`type` (`product` | `package`) tells the frontend which detail route to link to.
Packages carry no plan data in the light card — frontends should link packages
through to their detail page for plan selection rather than adding them directly
(the same rule the quick-view modal follows).

---

## Integration points

### Leads

`POST /api/v1/leads` captures the `X-Cart-Token` header and stores it as `leads.cart_ulid`. This pairing is enforced at checkout: if a lead has a `cart_ulid` set, the submitted `cart_ulid` must match via `hash_equals()` — a mismatch returns `403`.

### Checkout (`POST /api/v1/checkout`)

The checkout controller loads the cart by ULID, verifies the lead/cart session pairing, then passes the cart and lead to the configured checkout provider (e.g. `PrescribeRxCheckout`). See `app/Http/Controllers/Api/V1/Checkout/CheckoutController.php`.

### Catalog

`CartItem` polymorphically references `App\Models\Catalog\Product` and `App\Models\Catalog\Package`. The `plan_id` FK points to `App\Models\Catalog\Plan`. Deleting a catalog item does not cascade-delete cart items (the FK uses `nullOnDelete` for plan; product/package items remain orphaned in the `itemable` morph columns).

---

## Gotchas and design notes

- **`CartService` vs `CartController` are parallel implementations.** `CartService` is session-based and intended for Livewire/admin flows. `CartController` operates directly on the database. They do not share logic. If you add coupon or discount logic, apply it in both places or consolidate behind a shared abstraction.

- **Price snapshot is set at add-to-cart time only.** Subsequent price changes in Filament do not update existing cart items. If you implement a "price changed" warning at checkout, compare `unit_price_snapshot` against the live catalog price.

- **`CartService` supports `plan` as a standalone resource type** (the session store allows `resource_type = 'plan'`), but the REST API only accepts `product` or `package` as the `type` field. There is no `POST /api/v1/cart/items` path for adding a bare plan without a package.

- **Factories exist** for both `Cart` and `CartItem` at `Database\Factories\Commerce\CartFactory` and `Database\Factories\Commerce\CartItemFactory`. The `CartFactory` includes an `expired()` state that sets `expires_at` to one day in the past.

- **No scheduled cleanup** of expired carts is currently implemented. The `expires_at` column is checked on read only; old rows accumulate in the database.
