# Checkout Module — Developer Guide

**Status:** Existing embed handoff remains in service. The isolated Customer commerce branch adds durable API checkout attempts and verified local ownership; deployment is separate. Local gateway payment wiring, the durable financial ledger and provider reconciliation remain incomplete.

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

**Session pairing check:** The PRX action requires a persisted, nonempty `leads.cart_ulid` matching the submitted cart via `hash_equals()`. Missing or mismatching binding returns `403`. The controller also rejects a mismatching binding before either checkout path.

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
| 403 | Cart/lead session mismatch or missing PRX session binding |
| 409 | An existing PRX attempt is awaiting confirmation, or its frozen request/cart differs |
| 422 | Missing `payment_method`; payment declined; empty cart — i.e. an `ActionException`. Also a provider 422, whose `errors` are forwarded but whose message is not |
| 502 | The clinical provider failed. Its message is never relayed |
| 503 | An unhandled exception, or a deployment fault the shopper cannot fix (unmapped catalog, no gateway) |

See *Error messages: the TYPE decides what a shopper may be told* below — the status is chosen for
what the CALLER should do, not for where the failure was detected.

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
4. Throw `ActionException` if `PaymentResult::success === false` (controller returns 422 and relays
   the gateway's decline reason — see the error-message section below; a bare `RuntimeException`
   here would be swallowed into a generic 503, which is the point)
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
- The patient carries an explicit `shipping_address` / `billing_address` pair
  rather than the legacy single `address`, and exactly one shape is sent.
  `billing_same_as_shipping` tells their side to mirror. The SHIPPING address
  is the load-bearing one — its state decides which licensed clinician can be
  assigned — and a partial address resolves to null rather than being sent, as
  an incomplete one 422s the whole intake.
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

## Error messages: the TYPE decides what a shopper may be told

`CheckoutController` used to relay `$e->getMessage()` from any `RuntimeException`
as a 422. `lib/checkoutClient.js` puts a failure's `message` straight on the page,
so that is where these would have surfaced the moment anything called this
endpoint. That made the relay rule *"it is a RuntimeException"* rather than
*"someone wrote this sentence for a customer"*, and three different things went
out through it:

| Went out | Should have |
|---|---|
| `'Cart is empty.'` | ✅ correct — written for a shopper |
| `'No Prescribe-Rx selections found on cart items. Map the catalog first: packages need provider_package_id / …'` | ❌ an **operator** diagnostic, naming our provider id columns, shown to a customer who neither caused it nor can fix it |
| `PrescribeRxException` — also a `RuntimeException` | ❌ the clinical provider's own error text: absolute filesystem paths, and on one endpoint the full SQL statement with a `patient_chart_id` in it |

**`App\Actions\Exceptions\ActionException` is now the contract.** Throw it only
with a sentence you would be happy to show a customer; the controller relays its
message and nothing else's. A bare `RuntimeException` falls through to the
`Throwable` branch — logged, generic 503 — which is the right default for a
message nobody wrote for a shopper.

`PrescribeRxException` gets its own branch, following the patient portal's rule
(`bootstrap/app.php`, and `docs/portal/dev.md`): a 422's field-keyed `errors`
array is forwarded, because the storefront points at inputs with it, and the
provider's own prose never is.

The two are **not identical**, deliberately: the portal passes 403/404/409/429
through with its own copy, while checkout maps every non-422 to 502. A shopper
mid-purchase has one thing to know — we could not take the order — and a menu of
upstream statuses does not help them. One consequence to keep in mind:
`PrescribeRxException::notConfigured()` (status 0) reaches a shopper here as
"try again in a moment" for a fault that will not fix itself.

Two relays are deliberate and stay:

- **The gateway's decline reason** (`SubmitLocalCheckoutAction`) — "card
  declined", "address does not match". It is the one thing that tells the shopper
  what to change, and it is the only third-party string this app relays verbatim.
- **A provider 422's `errors` array** — field names and rule text, no prose.

`ApiController::error()` gained an `$errors` parameter to carry that. The base
class had documented the `{ message, errors }` envelope since it was written but
could only emit the first half.

🔴 **Reachability, so the fix is not oversold.** `POST /api/v1/checkout` is a
public route, but it is **not** in the storefront's proxy allowlist
(`atlas-protocol-web/lib/backendProxy.js`) — this deployment is on
`checkout_path = prx`, which never calls it. So the leak was reachable by anyone
addressing the API directly, and would have been reachable from the storefront's
own browser code the moment the deployment moved to `local` and allowlisted the
path. `CheckoutErrorLeakTest` pins all three cases, mutation-checked.

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


## API-driven PRX checkout: durable local attempt (September 14)

`SubmitPrescribeRxCheckoutAction` commits a pending Order, immutable item/amount snapshots, and a `checkout_attempts` row before making the unified-intake call. Lead and Cart primary-key locks serialize preparation; unique cart and `(cart_id, lead_id)` constraints protect the attempt identity. A different lead cannot bypass an existing cart attempt; any new purchase requires a fresh cart. No database transaction spans the provider request. The provider receives `metadata.checkout_context_uuid` and the stable `checkout-{uuid}` idempotency key. This context supports future verified metadata/webhook correlation; no webhook ingestion or outbound pixel delivery is added here.

Only fully representable carts can be submitted. An unmapped line rejects the whole purchase; invalid quantity/price and package quantity greater than one are also rejected (the provider package selection has no quantity field). Catalog/provider request and cart identity are frozen with keyed SHA-256 fingerprints. Raw intake answers are never stored in the attempt ledger. The small saved result containing provider references is encrypted. A hidden, encrypted `provider_receipt` containing only encounter ID/number, chart ID and status commits in its own transaction immediately after a valid provider response, before finalization. It survives finalization rollback for later local reconciliation; it contains no provider body, workflow, preclusions, or answers. Blank encounter/chart references are rejected as unknown.

`checkout_attempts.status` has three states:

- `submitting`: the local purchase exists and the provider call may be running. A worker crash can leave this state; it is never treated as permission to retry.
- `unknown`: the call failed, timed out, or local finalization failed. The pending Order and item snapshots survive. Even validation errors are conservatively unknown until reconciled.
- `completed`: the provider response was bound to the existing Order and a trusted `Encounter.lead_id`. Same-request retries return the saved response, including after the cart was cleared.

Every existing noncompleted attempt refuses a second provider call with 409, including after the provider idempotency TTL. The unified-intake HTTP transport also makes only one attempt; provider read retry behavior is unchanged. Trusted internal tooling can inspect attempt UUID, order ID, state, environment, and timestamps read-only; no attempt-management UI is added in this increment. The [local reconciliation command](reconciliation.md) can finish a retained receipt under its recorded typed provider binding. Never delete/reset an attempt or rerun checkout to resolve an uncertain outcome. There is no automated resubmission path.

For an already claimed Lead, the verified claim linker checks account/chart evidence before assigning the pending Order's Customer. After a canonical provider response, the same linker verifies and links the finalized Order. Anonymous Orders stay unowned until a verified claim. Neither Customer input nor an email match establishes ownership. Portal authentication is unchanged.

Finalization deletes only observed cart item IDs when their fingerprint still matches the frozen purchase. Edits made during the provider call survive and cannot replay as a different purchase. Changed intake answers also receive 409; successful replay never submits them again. A new purchase requires a new cart/session. This only changes API-driven PRX checkout; the embed flow and local payment charging need their own later durable attempt integration.

Validation: `LocalFirstPrescribeRxCheckoutTest` covers pre-network persistence, unchanged replay, uncertain outcomes beyond TTL, nested in-progress submission, changed cart/answers, unmapped lines, and missing session binding. `UnifiedIntakeSelectionTest` keeps provider payload coverage and verifies that an HTTP 500 triggers exactly one transport attempt. All provider traffic is faked.


### Repeat purchases and cart token rotation

Normal cart endpoints rotate a completed checkout cart once, persist its `successor_cart_id`, and return the successor token through the existing `data.token` contract. Reusing an older token follows the same successor chain. Retained rows edited during the provider call move with their IDs and quantities intact; route-bound item updates re-read their ownership after rotation. Cart resolution and mutation share a transaction and primary-key locks so a late edit cannot land on a predecessor after its rows moved. Original checkout requests still address the original attempt and can replay its saved result.

Submitting/unknown attempts never rotate automatically; expired uncertain tokens receive 409. Every token in a successor chain must be unexpired before it can expose successor data. Expired completed or unsubmitted tokens receive a fresh independent empty cart, preserving the existing bearer-token lifetime. The successor is a fresh cart and requires its own lead binding for a new purchase. Cart pruning retains carts referenced by durable attempts or predecessor links. Existing bearer-token semantics continue: an old token follows the same logical cart, so a later authorized clear using that token clears the current successor.


## Provider-instance binding and retained-receipt reconciliation

See [the reconciliation guide](reconciliation.md) for explicit typed namespace registration, Settings selection, immutable attempt binding, default rollback preview and audited local apply. New API submissions require that binding; legacy unbound attempts are not inferred from current settings. No provider query/resubmission, payment operation, webhook receiver or marketing delivery is introduced.
