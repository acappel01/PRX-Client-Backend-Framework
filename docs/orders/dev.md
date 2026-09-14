# Orders Module — Developer Guide

**Status:** Shipped 2026-06-28

---

## Data model

```
encounters
  └── hasMany → orders
                  └── hasMany → order_items
                  └── hasMany → order_shipments
                                  └── belongsToMany → order_items (via order_shipment_items, pivot: quantity)
```

### `orders`

| Column | Type | Notes |
|---|---|---|
| `uuid` | char(36) | Route key only; never an access credential. Auto-generated on create. |
| `encounter_id` | bigint nullable | FK → encounters. Null until webhook backfills on first delivery. Set at checkout. |
| `prescribe_rx_order_id` | varchar(64) nullable unique | PRX's internal order UUID. Null at checkout; backfilled by first webhook. |
| `prescribe_rx_order_number` | varchar(64) nullable | Human-readable order number (e.g. `RX-123456`). Backfilled by webhook. |
| `status` | varchar(32) | `OrderStatus` enum value. Default `pending`. |
| `subtotal` / `tax_amount` / `shipping_amount` / `discount_amount` / `total_amount` | decimal(10,2) | All nullable; populated by webhook. `subtotal` set at checkout from cart. |
| `currency` | varchar(3) | Default `USD`. |
| `shipping_address` / `billing_address` | text | Encrypted at model level (`encrypted:array`). Never exposed via API. |
| `placed_at` | timestamp | Set at checkout submission. |
| `shipped_at` / `delivered_at` / `cancelled_at` / `refunded_at` | timestamp nullable | Set once, never overwritten, by the corresponding webhook event. |

### `order_items`

| Column | Notes |
|---|---|
| `prescribe_rx_product_id` | PRX product UUID. Used for shipment item reconciliation. |
| `prescribe_rx_product_number` | PRX product number fallback. |
| `name` | Encrypted (`encrypted` cast). Product name at snapshot time. |
| `sku` | Provider SKU. |
| `quantity` | Unit count. |
| `unit_price` / `line_total` | decimal(10,2). Immutable snapshot. |
| `billing_period` | Subscription cadence (e.g. `monthly`). Null for one-time items. |

### `order_shipments`

| Column | Notes |
|---|---|
| `prescribe_rx_shipment_id` | PRX's shipment UUID. Lookup key for idempotent upsert. |
| `status` | `ShipmentStatus` enum. |
| `carrier` | `USPS`, `UPS`, `FedEx`, `DHL`. |
| `tracking_number` / `tracking_url` | Carrier tracking. |
| `fulfillment_center` | Opaque FC code from PRX. |
| `shipped_at` / `delivered_at` / `exception_at` | Set from webhook payload timestamps. |
| `exception_reason` | Carrier exception message. |

### `order_shipment_items` (pivot)

Junction between `order_shipments` and `order_items` with a `quantity` column to handle split-FC shipments where part of a quantity ships from one FC and the remainder from another.

---

## Enums

### `App\Enums\OrderStatus`
`Pending | Processing | Shipped | PartiallyShipped | Delivered | Cancelled | Refunded`

Methods: `label()`, `color()` (for Filament badges).

### `App\Enums\ShipmentStatus`
`Pending | LabelCreated | Shipped | InTransit | Delivered | Exception | Cancelled`

Methods: `label()`, `color()`.

---

## Actions

### Webhook sync

Orders are updated by prescribe-rx webhooks through the provider-agnostic inbound ledger —
`PrescribeRxWebhookHandler`, documented in [`../webhooks/dev.md`](../webhooks/dev.md). In short:
**update-only** (a webhook never creates an order), matched by `prescribe_rx_order_id` or, on the
first event, via the encounter to the checkout-created order, which then gets its provider id and
number; provider workflow / payment / shipping statuses stored beside the coarse `status`;
timestamps set once; an older event never overwrites a newer one.

---

## API endpoints

### `GET /api/v1/orders/{uuid}`

Retrieve an order by UUID using the existing authenticated portal session. The UUID identifies the order and does not grant access.

**Auth:** Existing Patient Sanctum session with `patient:*` (legacy `*` tokens retain Sanctum wildcard semantics), including idle/absolute expiry and the current portal two-factor enrollment policy. Operator, partner, and API-client tokens cannot use this endpoint, even with wildcard abilities. No new shopper tokens or login flow are introduced.

**Ownership:** `order.customer_id` must reference a non-deleted Customer whose `portal_account_id` is the authenticated Patient. A non-null legacy `order.patient_id` must also match that account. Missing orders, unowned orders, deleted Customers, detached accounts, and conflicting ownership all return the same `404`. There is no email, provider-chart, encounter, or legacy-account fallback, and this read never assigns or backfills ownership.

**Responses:** `401` without a valid portal identity/session; `403` for missing portal abilities or when the portal requires two-factor setup; `404` for missing or inaccessible orders. Every response has `Cache-Control: no-store` and crawler exclusion headers. The authorized `200` resource shape is unchanged.

**Compatibility:** This intentionally closes the former anonymous UUID lookup. Anonymous orders remain unowned until the existing verified mailbox/chart claim succeeds. The trusted claim writer then binds active local orders through checkout-created Encounter.lead_id evidence. API checkout also binds orders for an already claimed Lead. Unassigned historical/provider-import orders remain inaccessible until an independently authorized reconciliation exists. Do not infer ownership from checkout UUID possession. No caller of this detail endpoint was found in the current storefront source; `/api/v1/patient/orders` remains the existing separate clinical portal endpoint.

**Response `200`:**
```json
{
  "data": {
    "uuid": "01hxx...",
    "status": "shipped",
    "subtotal": "149.00",
    "tax_amount": "0.00",
    "shipping_amount": "0.00",
    "discount_amount": "0.00",
    "total_amount": "149.00",
    "currency": "USD",
    "placed_at": "2026-06-28T12:00:00.000000Z",
    "shipped_at": "2026-06-29T08:00:00.000000Z",
    "delivered_at": null,
    "cancelled_at": null,
    "prescribe_rx_order_number": "RX-123456",
    "items": [
      {
        "id": 1,
        "name": "Testosterone Cream",
        "sku": "TC-200",
        "quantity": 1,
        "unit_price": "149.00",
        "line_total": "149.00",
        "billing_period": "monthly"
      }
    ],
    "shipments": [
      {
        "id": 1,
        "status": "shipped",
        "carrier": "USPS",
        "tracking_number": "9400111899223418527401",
        "tracking_url": "https://...",
        "shipped_at": "2026-06-29T08:00:00.000000Z",
        "delivered_at": null,
        "exception_at": null,
        "exception_reason": null
      }
    ]
  }
}
```

**Intentional omissions:** `shipping_address`, `billing_address` — encrypted and omitted from the commerce detail response even for the owner.

### `POST /api/webhooks/prescribe-rx`

The prescribe-rx webhook receiver (not under `/api/v1`). Signed with
`X-PrescribeRx-Signature`; records the event and processes it on the queue. Contract, status
codes and handling: [`../webhooks/dev.md`](../webhooks/dev.md).

---

## Checkout flow (summary — see `docs/checkout/dev.md` for full detail)

```
POST /api/v1/checkout
  → SubmitPrescribeRxCheckoutAction
      → DB transaction: pending Order + snapshotted OrderItems + CheckoutAttempt
      → PRX: submitUnifiedIntake() once, outside DB locks
      → Separate DB transaction: encrypted minimal provider receipt
      → DB transaction:
          → trusted Encounter::create + attach existing Order
          → verify claimed Lead/Customer ownership where applicable
          → Lead: status = handed_off
          → attempt completed with encrypted saved result
          → clear only unchanged snapshotted cart items
  → returns: order_uuid, checkout_path, PRX encounter data
  → uncertain outcome: retained pending order/attempt; no automatic resubmission
```

The `prescribe_rx_order_id` on the order is blank until the first PRX order webhook arrives. `PrescribeRxWebhookHandler` matches it via `encounter → orders` on that event and backfills the id and number.

---

## Integration points

- **`Encounter`** — `Order.encounter_id` links to the clinical encounter. `Encounter.orders()` is a `HasMany`.
- **`Lead`** — linked via `Encounter.lead_id`. Not directly on Order.
- **`FulfillmentCenter`** — optional `Order.fulfillment_center_id` FK; exposed in `OrderResource` when loaded.
- **Patient portal** — `GET /api/v1/patient/orders` returns orders for an authenticated patient via the portal controller.

---

## Gotchas

- **`prescribe_rx_order_id` is nullable** — by design. PRX assigns the order ID asynchronously after checkout. The column was made nullable in migration `2026_06_28_234211`. Any code doing `where('prescribe_rx_order_id', ...)` must account for null rows.

- **Order items are encrypted at the name column** — `OrderItem.name` uses the `encrypted` Eloquent cast. Queries that filter or sort on `name` will not work at the database level.

- **Timestamp columns set once** — the webhook handler only sets `shipped_at`, `delivered_at`, `cancelled_at`, `refunded_at` when the field is currently null. Re-delivering an event does not reset the timestamp.

- **No local payment path yet** — `CheckoutController` returns 503 for `checkout_path = local`. When NMI/AuthNet local checkout is wired, it will go through `PaymentGatewayManager` and create the Order directly rather than via PRX.
