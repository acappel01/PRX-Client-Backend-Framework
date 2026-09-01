# Checkout Module — Developer Guide

**Status:** Complete (PRX embed-handoff path live end-to-end; local gateway path complete server-side, frontend payment UI pending the merchant-accounts milestone)

---

## Overview

Checkout bridges a visitor's cart + lead to the payment/clinical provider. A single toggle in `BillingSettings` controls which path is active:

| `checkout_path` | Provider | Who charges the card |
|---|---|---|
| `prx` *(default)* | Prescribe-Rx embed | PRX collects payment + intake inside its hosted embed |
| `local` | Configured merchant account | This app charges via NMI / Auth.Net / Stripe / Square |

Both paths return the same `CheckoutResultData` shape. The frontend branches on `checkout_path` to decide the next step.

The frontend learns the active path (plus upsell knobs) from the `checkout` block of `GET /api/v1/config`:

```json
{ "checkout": { "path": "prx", "upsells": { "enabled": true, "limit": 4 } } }
```

---

## Embed-first frontend flow (`checkout_path = prx`)

The default, shipped flow. The frontend never collects payment or clinical data — it captures a lead and hands the browser to a server-rendered page hosting the PRX embed.

```
Frontend /checkout page
  1. lead-capture form (contact, demographics, address, consents)
  2. POST /api/v1/leads  (X-Cart-Token header pairs the cart → leads.cart_ulid)
  3. redirect browser to the response's `handoff_url`
        ▼
GET /checkout/handoff/{lead:uuid}   ← the ONLY server-rendered public page
  - resources/views/pages/checkout/handoff.blade.php, minimal styling (brand-agnostic)
  - App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder::forLead() builds the
    embed SDK payload: prefill (lead demographics), selectPackages/selectProducts/
    selectPlan (from the lead's cart_items snapshot, translated to PRX numbers),
    skipSteps (config/prescribe-rx.php → embed.skip_steps), metadata (lead uuid, UTM)
  - embed code comes from IntegrationSettings::$prescribe_rx_embed_code; the page
    renders a clear "Embed code not configured" fallback when unset
        ▼
PRX embed runs clinical intake + payment
  - onComplete → POST /api/internal/checkout/embed-complete (advisory ping only)
  - authoritative state: POST /api/webhooks/prescribe-rx (HMAC-verified via
    VerifyPrescribeRxSignature middleware; idempotent, at-least-once)
```

`POST /api/v1/checkout` is **not called** in this flow — it exists for the local
gateway path and for API-driven PRX submission (unified intake without the embed).

Upsell placements (cart drawer + checkout page) are fed by
`GET /api/v1/cart/suggestions` — documented in `docs/cart/dev.md`.

---

## API endpoints

### `GET /api/v1/checkout/gateway-config`

**Auth:** Unauthenticated (rate-limited). Called on page load to initialise the client-side tokenization SDK.

| Gateway | SDK | Key field |
|---|---|---|
| `nmi` | Collect.js | `public_key` |
| `authorize_net` | Accept.js | `public_key` |
| `stripe` | Stripe.js | `public_key` |
| `square` | Square Web Payments SDK | `public_key` (application_id) + `location_id` |

**Response `200`:**
```json
{
  "data": {
    "gateway_provider": "stripe",
    "environment": "sandbox",
    "public_key": "pk_test_abc123..."
  }
}
```

**Response `503`:** No active default merchant account configured.

---

### `POST /api/v1/checkout`

**Auth:** Unauthenticated (rate-limited to 20 req/min).

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `cart_ulid` | string | Yes | ULID of the cart to check out |
| `lead_uuid` | string | Yes | UUID of the lead created at checkout start |
| `intake_answers` | object | No | Pre-filled answers for PRX intake. Ignored on local path. |
| `payment_method` | object | Local only | Tokenized payment data from the gateway SDK (see below) |

**`payment_method` shape by gateway:**

| Gateway | Required keys |
|---|---|
| NMI | `{ "payment_token": "..." }` |
| Authorize.Net | `{ "dataDescriptor": "...", "dataValue": "..." }` |
| Stripe | `{ "payment_method_id": "pm_..." }` |
| Square | `{ "nonce": "..." }` |

**Session pairing check:** If `leads.cart_ulid` is set, it must match `cart_ulid` via `hash_equals()`. Returns `403` on mismatch.

**Response `201` — PRX path:**
```json
{
  "data": {
    "order_uuid": "...",
    "checkout_path": "prx",
    "prescribe_rx": {
      "encounter_id": "prx-encounter-uuid",
      "encounter_number": "ENC-12345",
      "patient_id": "prx-patient-uuid",
      "status": "pending_intake"
    }
  }
}
```

**Response `201` — local path:**
```json
{
  "data": {
    "order_uuid": "...",
    "checkout_path": "local",
    "prescribe_rx": null
  }
}
```

**Error responses:**

| Status | Cause |
|---|---|
| 403 | Cart/lead session mismatch |
| 422 | Missing `payment_method` on local path; payment declined; empty cart; PRX rejection |
| 503 | Unhandled exception |

---

## Routing logic

```
BillingSettings.checkout_path
  'local'  →  validate payment_method present  →  SubmitLocalCheckoutAction
  'prx'    →  SubmitPrescribeRxCheckoutAction
```

Both actions return `CheckoutResultData`.

---

## Action: `SubmitLocalCheckoutAction`

### Flow

1. Verify cart is not empty
2. Resolve default active `MerchantAccount` via `PaymentGatewayManager`
3. **Charge the gateway outside the DB transaction** — a rollback cannot reverse a captured payment
4. Throw `RuntimeException` if `PaymentResult::success === false` (controller returns 422)
5. Inside `DB::transaction`:
   - `Order::create` — subtotal/total from `cart->subtotal()`, no `encounter_id`
   - `OrderItem::create × N` — snapshotted from cart items
   - Store in `order.metadata`: `gateway_transaction_id`, `merchant_account_id`, `gateway_provider`, `lead_uuid`
   - `Lead::update` → status `completed`
   - Delete cart items (cart record preserved for analytics)
6. Return `CheckoutResultData { order_uuid, checkout_path: 'local' }`

---

## Action: `SubmitPrescribeRxCheckoutAction`

### Selection resolution

The payload uses prescribe-rx's **modern selection arrays**, `products[]` and
`packages[]`. The legacy flat `product_ids` is deprecated on their side and is
no longer sent.

**A package is named, never flattened.** prescribe-rx already knows which
products a package contains, and keys real behaviour off the package row — a
labs hold before dispensing, a $0 shipping quote, consult-included pricing.
Sending member product ids discarded the package, so none of that fired.

Per cart item:

| Cart line | Emits |
|---|---|
| Package (no plan) | `packages[] = {package_id}` |
| Package + plan | `packages[] = {package_id, plan_id}` |
| Product | `products[] = {product_id, quantity, snapshot_price}` |
| Product + plan | `products[] = {product_id, …}` — their `products[]` has no `plan_id`, so the term is expressed by the local order, not the encounter |

A **plan is never itself a cart line**: `CartController::addItem` accepts
`type` in `product|package` only, and a chosen term arrives as `plan_id` on the
line. The resolver has no plan branch for that reason.

Each line carries **exactly one** identifier, per their contract: the UUID
(`package_id` / `product_id`) is preferred because it survives a rename on
their side, and the human-readable number (`package_number` /
`product_number`) is the fallback for an item mapped by SKU alone. Unset
identifiers are stripped before transport — a line carrying two is rejected.

An unmapped item is skipped and **logged**; the action throws only when the
whole cart resolves to nothing. A partially-mapped cart therefore submits,
naming only what it could resolve.

### Idempotency

The submission sends `Idempotency-Key: {app-name-slug}-{cart.ulid}-{lead.uuid}`,
namespaced per install so deployments sharing a prescribe-rx tenant cannot
collide. Their
side replays a stored response for 24h, so a retry of the same submission
cannot mint a second encounter for one patient. The key must stay stable
across retries — do not add a timestamp.

### Other payload notes

- `is_sandbox` is only ever **asserted**, never denied. Their server auto-flags
  test-looking names as sandbox; an explicit `false` could override that, so a
  production submission omits the key entirely.
- `metadata` carries the lead uuid, cart ulid and UTM attribution, so an
  encounter can be traced back to the visit that produced it.
- `gender` is translated, not passed through. Our lead form offers
  `prefer_not_to_say`; they accept only `male` / `female` / `other`. An
  unmappable value is **dropped** — declining to answer is not "other", and
  guessing would put a wrong answer on a clinical chart.

### Transaction boundary

The PRX HTTP call is made **outside** the DB transaction. The transaction wraps only local DB writes:

```
DB::transaction:
  Encounter::create     ← prescribe_rx_encounter_id from PRX response
  Order::create         ← encounter_id set; prescribe_rx_order_id = null (backfilled by webhook)
  OrderItem::create × N
  Lead::update          ← status = handed_off, PRX IDs stored
  Cart items: delete
```

---

## BillingSettings

**Class:** `App\Settings\BillingSettings` | **Group:** `billing`

| Property | Default | Values |
|---|---|---|
| `checkout_path` | `'prx'` | `'prx'` or `'local'` |
| `upsells_enabled` | `true` | Show upsell suggestions in cart drawer + checkout |
| `upsells_limit` | `4` | Max suggestions per `GET /cart/suggestions` response (1–12) |

**Admin:** Settings → Billing (checkout-path radio + upsells section).

Every `Update*SettingsAction` (Billing included) clears the cached
`/api/v1/config` bundle (`Cache::forget('api.v1.config')`) so the frontend sees
path/upsell changes on its next boot call instead of waiting out the 5-minute
TTL. Covered by `tests/Feature/Settings/ConfigCacheInvalidationTest`.

---

## DTOs

| Class | Purpose |
|---|---|
| `CheckoutData` | Input — `cart_ulid`, `lead_uuid`, `intake_answers`, `payment_method?` |
| `CheckoutResultData` | Output — `order_uuid`, `checkout_path`, `prescribe_rx?` |

---

## Files

```
app/
├── Actions/Checkout/
│   ├── SubmitPrescribeRxCheckoutAction.php
│   └── SubmitLocalCheckoutAction.php
├── Actions/Settings/UpdateBillingSettingsAction.php
├── Data/Checkout/CheckoutData.php
├── Data/Checkout/CheckoutResultData.php
├── Data/Settings/BillingSettingsData.php
├── Filament/Pages/Settings/ManageBilling.php
├── Http/Controllers/Api/V1/Checkout/CheckoutController.php
└── Settings/BillingSettings.php

database/settings/2026_06_29_020250_create_billing_settings_migration.php

tests/Feature/
├── Api/V1/Checkout/CheckoutControllerTest.php  (PRX path — 8 tests)
├── Api/V1/Cart/CartSuggestionsTest.php         (upsell suggestions — 7 tests)
└── Checkout/LocalCheckoutTest.php              (local path — 8 tests)
```

Embed-handoff surface (outside `app/Actions/Checkout`):

```
routes/web.php                                   GET /checkout/handoff/{lead:uuid}
resources/views/pages/checkout/handoff.blade.php
app/Services/PrescribeRx/Embed/PrxEmbedPayloadBuilder.php
app/Http/Controllers/PrescribeRx/EmbedCompleteController.php   (advisory)
app/Http/Controllers/PrescribeRx/WebhookController.php         (authoritative)
app/Http/Middleware/VerifyPrescribeRxSignature.php
```

---

## Gotchas

- **`intake_answers` is almost always empty at checkout** — PRX embed collects intake after handoff. It's a pass-through for future pre-fill scenarios.
- **`payment_method` is gateway-specific** — the frontend must use the gateway SDK matching `GET /checkout/gateway-config` response to tokenize before submitting. Never send raw card numbers.
- **`prescribe_rx_encounter_type_id` must be set** in Integration Settings before PRX checkout works.
- **Local path: gateway is charged before DB writes** — if the transaction fails after a successful charge, the order is missing but the payment exists. Recover by checking the gateway dashboard and manually creating the order.
