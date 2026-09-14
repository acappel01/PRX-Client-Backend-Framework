# Payments Module — Developer Guide

**Status:** Gateway abstractions shipped 2026-06-28; passive intent/uncertainty ledger added 2026-09-14. Financial execution remains unqualified.

See [passive ledger contract](passive-ledger.md) for current local persistence, tested boundaries and concrete gateway integration blockers. Older gateway descriptions below do not establish payment readiness.

---

## Data model

```
merchant_accounts
  ├── gateway credentials (per-provider, encrypted at rest)
  ├── capability flags
  ├── volume tracking
  └── surcharge config
```

The original gateway layer stores merchant accounts. The passive ledger now adds `payment_intents` and `payment_operations`; neither table records confirmed financial transactions. Verified transaction ingestion and usable vault references remain future work.

### Table: `merchant_accounts`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `uuid` | uuid | Public route key. Auto-generated on create. |
| `name` | string | Internal label |
| `gateway_provider` | string(32) | `GatewayProvider` enum value: `nmi`, `authorize_net`, `stripe`, `square` |
| `environment` | string(16) | `GatewayEnvironment` enum value: `sandbox`, `production` |
| `nmi_security_key` | text | Encrypted. NMI Direct Post security key. |
| `nmi_public_key` | string | Plain text. NMI Collect.js client-side key. |
| `authnet_api_login_id` | text | Encrypted. Authorize.Net API Login ID. |
| `authnet_transaction_key` | text | Encrypted. Authorize.Net Transaction Key. |
| `authnet_public_client_key` | string | Plain text. Accept.js client-side key. |
| `authnet_signature_key` | text | Encrypted. Authorize.Net webhook HMAC key. |
| `cim_enabled` | boolean | Authorize.Net Customer Information Manager. |
| `stripe_secret_key` | text | Encrypted. `sk_live_` / `sk_test_` prefix. |
| `stripe_publishable_key` | string | Plain text. `pk_live_` / `pk_test_` prefix. |
| `stripe_webhook_secret` | text | Encrypted. `whsec_` prefix. |
| `square_application_id` | string(128) | Plain text. Web Payments SDK client ID. |
| `square_access_token` | text | Encrypted. Server-side bearer token. |
| `square_location_id` | string(64) | Plain text. Required on all Square Payments/Refunds calls. |
| `square_webhook_signature_key` | text | Encrypted. HMAC-SHA256 webhook key. |
| `is_active` | boolean | Indexed. Inactive accounts are skipped by the manager. |
| `is_default` | boolean | Indexed. Fallback when no specific account is selected. |
| `transaction_weight` | integer | Load balancing weight across active accounts. Default 1. |
| `monthly_volume_limit` | decimal(12,2) | Nullable. Null = unlimited. |
| `monthly_volume_used` | decimal(12,2) | Running total. Not auto-reset. |
| `auto_disable_at_limit` | boolean | If true, account is deactivated when limit is hit. |
| `auto_disabled_at` | timestamp | Nullable. When the account was auto-disabled. |
| `reactivate_on` | date | Nullable. Operator-set reactivation date. |
| `allows_recurring_payments` | boolean | Capability flag. |
| `allows_rx_processing` | boolean | Capability flag. |
| `allows_card_present` | boolean | Capability flag. |
| `allows_card_not_present` | boolean | Capability flag. |
| `supports_public_checkout` | boolean | Capability flag. |
| `notification_thresholds` | json | Nullable. Volume alert thresholds (structure TBD). |
| `surcharge_rate` | decimal(6,4) | Nullable. Percentage, e.g. `6.5000` = 6.5%. |
| `surcharge_flat_per_txn` | decimal(8,2) | Nullable. Fixed per-transaction surcharge in dollars. |
| `surcharge_passthrough` | boolean | Bill surcharge back to the sales org at settlement. |
| `gateway_endpoint_url` | string(512) | Nullable. Overrides the hardcoded gateway API base URL. |
| `metadata` | json | Nullable. Arbitrary key-value store for future use. |
| `deleted_at` | timestamp | Soft delete. |

### Encryption

Server-side secrets use Eloquent's `'encrypted'` cast, which applies `Crypt::encrypt()` / `Crypt::decrypt()` transparently. Client-safe public/publishable keys are stored plain text — they are meant to be exposed to browser JS.

Encrypted columns: `nmi_security_key`, `authnet_api_login_id`, `authnet_transaction_key`, `authnet_signature_key`, `stripe_secret_key`, `stripe_webhook_secret`, `square_access_token`, `square_webhook_signature_key`.

---

## Enums

### `GatewayProvider`

```php
GatewayProvider::Nmi          // 'nmi'
GatewayProvider::AuthorizeNet // 'authorize_net'
GatewayProvider::Stripe       // 'stripe'
GatewayProvider::Square       // 'square'
```

`->label()` returns human-friendly strings ("NMI", "Authorize.Net", "Stripe", "Square").
`->color()` returns a Filament badge color string.

### `GatewayEnvironment`

```php
GatewayEnvironment::Sandbox    // 'sandbox'
GatewayEnvironment::Production // 'production'
```

Used by gateway implementations to select the correct API base URL or SDK endpoint.

### `TransactionStatus`

```php
TransactionStatus::Pending
TransactionStatus::Authorized
TransactionStatus::Captured
TransactionStatus::Succeeded
TransactionStatus::Refunded
TransactionStatus::Voided
TransactionStatus::Failed
```

Not yet persisted to a transactions table. Intended for use when the Orders module records transaction state.

---

## Contracts and DTOs

### `PaymentGatewayInterface`

All four gateway classes implement `App\Contracts\Payments\PaymentGatewayInterface`:

```php
public function sale(string $merchantAccountId, string $amount, string $currency, array $paymentPayload): PaymentResult;
public function authorize(string $merchantAccountId, string $amount, string $currency, array $paymentPayload): PaymentResult;
public function capture(string $merchantAccountId, string $gatewayTransactionId, string $amount, string $currency): PaymentResult;
public function refund(string $merchantAccountId, string $gatewayTransactionId, string $amount, string $currency, array $context): PaymentResult;
public function void(string $merchantAccountId, string $gatewayTransactionId): PaymentResult;
public function storePaymentMethod(string $merchantAccountId, string $payableType, string $payableId, array $paymentPayload): PaymentResult;
```

**Amount convention**: all `$amount` parameters are dollar strings (e.g. `"29.99"`). Each gateway converts internally — NMI accepts dollar strings directly, Stripe and Square require integer cents (conversion done in the gateway class).

**`$merchantAccountId`**: the integer primary key of the `MerchantAccount` row. Gateways call `MerchantAccount::query()->findOrFail($merchantAccountId)` internally.

### `PaymentResult`

A `final` DTO returned by every gateway method:

```php
PaymentResult {
    bool         $success
    string       $status          // normalized: 'succeeded'|'authorized'|'captured'|'refunded'|'voided'|'stored'|'failed'
    ?string      $transactionId   // gateway's transaction ID (NMI transactionid, Stripe pi_xxx, etc.)
    ?string      $message         // human-readable result message
    ?string      $amount          // dollar string, echoed back
    ?string      $currency        // currency code, echoed back
    ?GatewayProvider $gatewayProvider
    ?array       $rawData         // full raw response from the gateway
}
```

Factory methods:
- `PaymentResult::from(array $p)` — construct from a loosely-shaped array (camelCase or snake_case keys are normalized).
- `PaymentResult::failure(string $message, ?GatewayProvider $provider)` — shorthand for a failed result.

---

## Services

### `PaymentGatewayManager`

`App\Services\Payments\PaymentGatewayManager` is the entry point for all gateway resolution.

```php
// Resolve from a MerchantAccount model
$gateway = $manager->forAccount($account);

// Resolve by numeric merchant account ID
$gateway = $manager->forAccountId('42');

// Resolve by GatewayProvider enum directly (creates a fresh instance)
$gateway = $manager->driver(GatewayProvider::Stripe);

// Resolve the active default account's gateway
$gateway = $manager->default(); // throws ModelNotFoundException if none
```

The manager is a plain class — register it in a service provider or resolve it via `app(PaymentGatewayManager::class)`. It is not auto-bound as a singleton; each call to `make()` creates a fresh gateway instance.

### `NmiGateway`

`App\Services\Payments\Gateways\NmiGateway`

- Transport: Laravel `Http::asForm()->post()` to `https://secure.nmi.com/api/transact.php`.
- Response parsing: NMI returns `key=value` pairs (`parse_str()`). Success is `response == '1'`.
- `storePaymentMethod` creates a customer vault entry (`customer_vault=add_customer`) with a `$0.00` sale. Returns `customer_vault_id` as `transactionId`.
- Payload keys for `paymentParams`:
  - Vaulted flow: `customer_vault_id`
  - Raw card (sandbox/test only): `ccnumber`, `ccexp`, `cvv`, `firstname`, `lastname`, `address1`, `city`, `state`, `zip`, `country`, `email`, `phone`

### `AuthorizeNetGateway`

`App\Services\Payments\Gateways\AuthorizeNetGateway`

- Transport: `anet/sdk-php` (the `net\authorize\api` namespace). Uses Authorize.Net's official PHP SDK.
- Environment: `GatewayEnvironment::Production` → `ANetEnvironment::PRODUCTION`; everything else → `ANetEnvironment::SANDBOX`.
- `sale` → `authCaptureTransaction`; `authorize` → `authOnlyTransaction`; `capture` → `priorAuthCaptureTransaction`.
- `refund` requires `last_four` in `$context` (last 4 digits of the card number) — Authorize.Net's refund API demands this for validation.
- `storePaymentMethod` uses the CIM API:
  - If `$paymentPayload['customer']['id']` is present → adds a payment profile to the existing customer (`CreateCustomerPaymentProfileController`).
  - Otherwise → creates a new customer profile (`CreateCustomerProfileController`).
  - On duplicate profile error (`E00039`) → retries as "add to existing customer".
  - Returns `transactionId` = the Authorize.Net payment profile ID.
- Payload keys for vaulted charge (`applyPaymentProfile`): `gateway_customer_id`, `gateway_payment_profile_id`.
- Payload keys for `storePaymentMethod`: `opaque_data.data_descriptor` + `opaque_data.data_value` (from Accept.js), or `payment_token.descriptor` + `payment_token.value`.

### `StripeGateway`

`App\Services\Payments\Gateways\StripeGateway`

- Transport: raw `Http::withBasicAuth($secretKey, '')` to `https://api.stripe.com/v1`. No Stripe PHP SDK dependency.
- API surface used: Payment Intents, Refunds, Customers, Payment Methods.
- `sale` → PaymentIntent with `capture_method=automatic, confirm=true`.
- `authorize` → PaymentIntent with `capture_method=manual, confirm=true`.
- `capture` → `payment_intents/{id}/capture`.
- `void` → `payment_intents/{id}/cancel`. Stripe `canceled` status maps to `voided`.
- `storePaymentMethod`:
  1. Creates a Stripe Customer if no `customer` ID is provided (tagged with `metadata[payable_type]` and `metadata[payable_id]`).
  2. Attaches the `payment_method` (`pm_xxx`) to that customer.
  3. Returns the Stripe Customer ID as `transactionId`.
- `gatewayTransactionId` throughout = Stripe PaymentIntent ID (`pi_xxx`).
- Amount conversion: dollar string → integer cents via `(int) round($amount * 100)`.
- Payload keys: `payment_method` (required for most operations), `customer` (optional Stripe Customer ID), `description`, `metadata` (key-value array).

### `SquareGateway`

`App\Services\Payments\Gateways\SquareGateway`

- Transport: raw `Http::withToken($accessToken)` to Square's REST API. No Square PHP SDK.
- Base URLs: `https://connect.squareup.com/v2` (production) / `https://connect.squareupsandbox.com/v2` (sandbox). Selected by `$ma->environment`.
- API version header: `Square-Version: 2024-01-18`.
- `sale` → `POST /payments` with `autocomplete=true`.
- `authorize` → `POST /payments` with `autocomplete=false`.
- `capture` → `POST /payments/{id}/complete`.
- `void` → `POST /payments/{id}/cancel`. Square `CANCELED` status maps to `voided`.
- `refund` → `POST /refunds` with `amount_money.amount` in cents and `payment_id`.
- `storePaymentMethod`:
  1. Creates a Square Customer if no `customer_id` is in the payload.
  2. Creates a card on file via `POST /cards` with `source_id` and `card.customer_id`.
  3. Returns the Square Customer ID as `transactionId`.
- All mutating Square calls include an `idempotency_key` (UUID generated per call).
- Amount conversion: dollar string → integer cents.
- Payload keys: `source_id` (required — Square payment token or card-on-file ID), `customer_id` (optional), `note`, `reference_id`.

---

## Model helpers

### `MerchantAccount::getPublicKey()`

Returns the client-safe public/publishable key for the account's gateway:

```php
match ($this->gateway_provider) {
    GatewayProvider::Nmi          => $this->nmi_public_key,
    GatewayProvider::AuthorizeNet => $this->authnet_public_client_key,
    GatewayProvider::Stripe       => $this->stripe_publishable_key,
    GatewayProvider::Square       => $this->square_application_id,
};
```

Use this when the frontend needs the tokenization key to initialize Collect.js / Accept.js / Stripe Elements / Square Web Payments SDK. Never return encrypted server-side keys to the browser.

### `MerchantAccount::hasValidCredentials()`

Returns `true` when all required server-side credentials are non-empty for the configured gateway. Used in the admin table "Creds" column for a quick health check. Does not make a live API call.

---

## API endpoints

There are currently no public `/api/v1/payments/*` routes. The module is a backend gateway abstraction only. The expected integration points when Cart/Checkout is built:

- A checkout action will call `PaymentGatewayManager::default()` (or resolve a specific account) to obtain the gateway, then call `sale()` or `authorize()`.
- A `GET /api/v1/checkout/public-key` endpoint (not yet built) should call `$account->getPublicKey()` to return the client-side tokenization key to the frontend.

---

## Integration points

### With Cart / Checkout (pending)

When the Cart module ships, checkout actions will:
1. Resolve the appropriate `MerchantAccount` (default, or a specific account for the product/package).
2. Call `PaymentGatewayManager::forAccount($account)` to get the gateway.
3. Call `$gateway->sale(...)` or `$gateway->authorize(...)`.
4. Persist the `PaymentResult` fields (`transactionId`, `status`, `amount`, etc.) to an order/transaction record.

### With Orders (pending)

Order models should store `merchant_account_id` (FK to `merchant_accounts.id`) and `gateway_transaction_id` for post-capture, refund, and void operations.

### With Settings

`BillingSettings` (in `IntegrationSettings`) has a toggle for routing checkout through prescribe-rx instead of a local merchant account. When that toggle is on, no `MerchantAccount` is used — the prescribe-rx embed handles payment entirely.

### With prescribe-rx

Some deployments route checkout entirely through prescribe-rx. In those deployments the Payments module merchant accounts are still configured for local fallback or for non-prescribe-rx products.

---

## Gotchas and non-obvious design decisions

**`$merchantAccountId` is the integer PK, not the UUID**
The `PaymentGatewayInterface` methods accept `string $merchantAccountId`. This is the integer database ID cast to string, not the UUID. The UUID is for public API route binding. This is an internal identifier used only within the service layer.

**Amount is a dollar string, not cents or a float**
`"29.99"` not `2999` or `29.99`. Each gateway converts to its required format internally. Passing a float risks floating-point precision errors — always pass a string.

**NMI endpoint is hardcoded**
`NmiGateway::ENDPOINT = 'https://secure.nmi.com/api/transact.php'`. The `gateway_endpoint_url` column exists on the model but NmiGateway does not yet read it. This was intentional — the override feature is available at the data layer but the gateway does not consume it yet.

**AuthorizeNet refund requires `last_four`**
Authorize.Net's refund API validates the last 4 digits of the card against the original transaction. Pass `$context['last_four']` when calling `refund()` on an Authorize.Net account.

**Stripe `storePaymentMethod` returns a Customer ID, not a PaymentMethod ID**
`PaymentResult::transactionId` is the Stripe Customer ID (`cus_xxx`) after a vault operation, not the `pm_xxx` PaymentMethod ID. The raw `pm_xxx` is in `rawData`.

**Square `storePaymentMethod` also returns a Customer ID**
Same convention — `transactionId` = Square Customer ID; the card-on-file ID is in `rawData['card']['id']`.

**Authorize.Net duplicate customer (E00039)**
If a customer profile already exists in Authorize.Net for the given `payableId`, the SDK returns error `E00039` with the existing `customerProfileId`. `AuthorizeNetGateway::createCustomerProfile` handles this by retrying as an "add payment profile to existing customer" call.

**`monthly_volume_used` is not auto-reset**
The field tracks running monthly volume but there is no scheduled command or cron job to reset it at the start of each billing cycle. This must be added before the volume cap feature can be relied upon in production.

**Soft deletes**
`MerchantAccount` uses `SoftDeletes`. Deleted accounts are excluded from all queries by default. The Filament resource configures `getRecordRouteBindingEloquentQuery` to include soft-deleted records so admins can still view/restore them.

**Actions and financial readiness**
`app/Actions/Payments/` now contains passive ledger preparation/uncertainty actions as well as the existing unwired checkout-payment and merchant-usage actions. Passive actions never call a gateway. The existing checkout-payment action requires the contract repairs listed in [passive ledger](passive-ledger.md) before integration; direct gateway calls from controllers are not the intended pattern.


### Durable follow-up reports

The passive ledger now also contains `PaymentOutcomeObservationData`,
`RecordPaymentOutcomeObservationAction`, `PaymentOutcomeObservation` and the
`ReportedPaymentOutcome` enum. These append encrypted unverified evidence without
changing payment state. See [passive ledger](passive-ledger.md#follow-up-outcome-observations-september-14-continuation)
for replay/account boundaries and the concrete Authorize.Net read-adapter gaps.
