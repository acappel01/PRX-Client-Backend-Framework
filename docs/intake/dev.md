# Intake Module — Developer Guide

**Status:** Shipped 2026-06-28

## What this module is

The intake module is the bridge between the local catalog and the prescribe-rx telehealth platform. It has two distinct surfaces:

1. **`GET /api/v1/intake/schema`** — a public API endpoint the React frontend calls to retrieve the dynamic clinical questionnaire for a given cart. The frontend renders the schema as a multi-step form; no hardcoded form fields exist anywhere in this codebase.

2. **The checkout handoff page (`/checkout/handoff/{lead:uuid}`)** — a server-rendered Blade page (the only server-rendered public page in the app) that builds the prescribe-rx embed payload from a Lead and renders the embed iframe. Clinical intake, payment, and encounter creation all happen inside the prescribe-rx embed.

No local database tables are owned exclusively by the intake module. It reads from the catalog tables and writes through the `MarkLeadHandedOffAction` / `MarkLeadCompletedAction` actions into the `leads` table. Encounters and orders created via webhooks land in the commerce tables.

---

## Data model

The intake module does not own any tables. It reads from:

```
products        <- provider_encounter_type_id (nullable string)
packages        <- provider_encounter_type_id (nullable string)
categories      <- provider_encounter_type_id (nullable string)
leads           <- cart_items (json), uuid, prefill demographic fields
```

Migrations that added encounter type routing:

- `2026_06_22_083853_add_provider_encounter_type_id_to_categories_table`
- `2026_06_22_084526_add_provider_encounter_type_id_to_products_and_packages`

### Key columns

| Table | Column | Purpose |
|---|---|---|
| `products` | `provider_encounter_type_id` | Product-level encounter type override |
| `packages` | `provider_encounter_type_id` | Package-level fallback when no product mapping exists |
| `categories` | `provider_encounter_type_id` | Category-level default; inherited by all products in the category |
| `leads` | `cart_items` (json) | Snapshot of cart at checkout start; contains `resource_type` (product/package/plan) and `resource_id` |

---

## Encounter type resolution

`IntakeSchemaController::resolveEncounterTypeId()` walks the cart in this order:

1. Expand any package IDs to their contained product IDs.
2. For each product: check `products.provider_encounter_type_id`.
3. For each product: walk its `categories` relationship and check `categories.provider_encounter_type_id`.
4. If still null: fall back to `packages.provider_encounter_type_id` on the original package IDs.
5. Return null if all fail → controller returns a 422.

When a cart contains multiple products that resolve to different encounter types, the first non-null result wins (in the order products are supplied). Multi-encounter-type carts are a frontend concern — the frontend should split them.

---

## Service layer

### `TelehealthManager` (`App\Services\Telehealth\TelehealthManager`)

Laravel Manager pattern. Resolves the active `TelehealthProviderInterface` implementation from `IntegrationSettings`. Forwards method calls via `__call()` so callers never depend on the concrete provider.

Resolution logic:
- `prescribe_rx_enabled = true` → `PrescribeRxProvider`
- otherwise → `NullTelehealthProvider` (safe no-op for fresh installs)

Inject `TelehealthManager` (not the interface directly) to allow the manager to swap providers at runtime.

### `PrescribeRxProvider` (`App\Services\Telehealth\PrescribeRxProvider`)

Implements `TelehealthProviderInterface`. Wraps `App\Services\PrescribeRx\Client` for methods already implemented there; issues raw HTTP calls via an internal `http()` helper for the rest. This class is the translation layer — it converts between the provider-agnostic interface contract and the prescribe-rx-specific `Client`.

Key methods relevant to intake:

| Method | Delegates to | Notes |
|---|---|---|
| `getEncounterTypeSchema(string $id): array` | `Client::getEncounterTypeSchema()` | Returns `EncounterTypeSchemaData::toArray()` |
| `listEncounterTypes(): array` | `Client::listEncounterTypes()` | Returns array of `EncounterTypeData::toArray()` |
| `submitIntake(array $payload): array` | `Client::submitUnifiedIntake()` | Wraps payload in `UnifiedIntakeRequestData`, returns `UnifiedIntakeResponseData::toArray()` |
| `buildEmbedPayload(Lead $lead): ?array` | `PrxEmbedPayloadBuilder::forLead()` | Used by the handoff route |
| `evaluatePreclusions(...)` | `http()` directly | POST /telehealth/preclusions/evaluate |

### `App\Services\PrescribeRx\Client`

Low-level HTTP client for the prescribe-rx API. All methods return typed DTOs or throw `PrescribeRxException`. Reads connection config from `config/prescribe-rx.php` and per-tenant credentials from `IntegrationSettings`.

Methods relevant to intake:

| Method | PRX endpoint | Returns |
|---|---|---|
| `listEncounterTypes(?string $telehealthCompanyId)` | `GET /telehealth/encounter-types` | `array<EncounterTypeData>` |
| `getEncounterTypeSchema(string $id)` | `GET /telehealth/encounter-types/{id}/schema` | `EncounterTypeSchemaData` |
| `submitUnifiedIntake(UnifiedIntakeRequestData $data)` | `POST /telehealth/intake/unified` | `UnifiedIntakeResponseData` |
| `issuePatientToken(string $patientChartId, array $abilities)` | `POST /patients/{id}/issue-token` | `array{token, expires_at, abilities, ...}` |
| `getEncounterStatus(string $encounterId)` | `GET /telehealth/encounters/{id}/status` | `array` |
| `findPatientByEmail(string $email)` | `GET /patients?filter[email]=X` | `?array` |

Two request methods exist:
- `request()` — authenticated with the sales-org token from `IntegrationSettings`. Used for all catalog and encounter management calls.
- `patientRequest(string $patientToken)` — authenticated with a patient-scoped token from `issuePatientToken()`. Used for `/me/patient/*` endpoints where PHI audit requires patient identity.

**Stub mode:** Set `PRESCRIBE_RX_STUB=true` in `.env` (or `config('prescribe-rx.stub') = true`). All `Client` methods return canned fixtures without hitting the network. Useful for local dev before a token is issued and in CI.

### `PrxEmbedPayloadBuilder` (`App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder`)

Builds the JSON payload the handoff Blade view passes to the prescribe-rx embed SDK. Two responsibilities:

1. Translate a Lead's demographic fields into the snake_case keys the embed's `prefill()` method expects. Emits both flat keys (`first_name`, `city`) and a nested `address` object — different schema versions of the embed accept different shapes.
2. Translate the Lead's `cart_items` JSON into prescribe-rx product/package/plan identifiers (`prescribe_rx_product_number` / `prescribe_rx_package_number` / `prescribe_rx_plan_id`).

Returns:
```php
[
    'embedCode'  => string|null,          // from IntegrationSettings
    'prefill'    => array,                // demographic fields from Lead
    'packages'   => array<string>,        // PRX package numbers (PKG-XXXXX)
    'products'   => array<string>,        // PRX product numbers (PROD-XXXXX)
    'planIds'    => array<string>,        // PRX plan UUIDs
    'skipSteps'  => array<string>,        // step slugs from config/prescribe-rx.php embed.skip_steps
    'metadata'   => array,               // lead_uuid, utm_*, cart_subtotal
]
```

Skip steps are configured at `config/prescribe-rx.php → embed.skip_steps`. Unknown slugs are silently ignored by the SDK, so it is safe to accumulate slugs for multiple encounter types.

---

## DTOs

All DTOs live in `App\Data\PrescribeRx\` and extend `Spatie\LaravelData\Data`.

| DTO | Direction | Purpose |
|---|---|---|
| `EncounterTypeData` | out | Summary of one encounter type from `GET /encounter-types` |
| `EncounterTypeSchemaData` | out | Full schema with `steps[]`, `field_slugs[]`, `required_slugs[]`, `meta` |
| `UnifiedIntakeRequestData` | in | Input to `POST /telehealth/intake/unified` |
| `UnifiedIntakeResponseData` | out | Response from unified intake (encounter_id, patient_chart_id, status, workflow) |
| `PatientData` | nested in request | Patient demographics; validates email, DOB format, gender enum |
| `VitalsData` | nested in request | height_inches, weight_lbs, BP, heart_rate |
| `MedicalHistoryData` | nested in request | allergies[], medications[], conditions[] arrays |
| `AddressData` | nested in PatientData | street, city, state, postal_code, country |
| `ConsentData` | nested collection in request | Consent items |

`EncounterTypeSchemaData` keeps `steps` and `fields` as loosely typed arrays rather than nested DTOs. This is intentional — prescribe-rx adds field types frequently and locking down the shape would require a redeploy for every schema change.

---

## API endpoints

### `GET /api/v1/intake/schema`

**Auth:** Unauthenticated (public). Rate-limited by the default API middleware.

**Controller:** `App\Http\Controllers\Api\V1\Intake\IntakeSchemaController`

**Query params:**

| Param | Type | Required | Description |
|---|---|---|---|
| `products[]` | integer | No | Local product IDs |
| `packages[]` | integer | No | Local package IDs |

At least one of `products[]` or `packages[]` should be provided; if neither is given and no encounter type can be resolved, the endpoint returns a 422.

**Response (200):**
```json
{
  "data": {
    "encounter_type_id": "019ce396-46a1-73ab-87d6-c40310555401",
    "schema": {
      "encounter_type": { "id": "...", "name": "GLP-1 Screening", "slug": "glp1-screening" },
      "steps": [
        {
          "step_name": "Eligibility",
          "step_type": "screening",
          "display_order": 1,
          "is_required": true,
          "fields": [
            {
              "slug": "glp1_diabetes_mellitus",
              "label": "Have you been diagnosed with Type 2 Diabetes?",
              "field_type": "radio",
              "is_required": true,
              "options": [{ "value": "Yes", "label": "Yes" }, { "value": "No", "label": "No" }]
            }
          ]
        }
      ],
      "field_slugs": ["glp1_diabetes_mellitus"],
      "required_slugs": ["glp1_diabetes_mellitus"],
      "meta": { "total_steps": 1, "total_fields": 1 }
    }
  }
}
```

**Error (422):**
```json
{
  "message": "No encounter type is mapped to the selected products. Configure one via Admin → Catalog."
}
```

---

## Web routes (non-API)

These live in `routes/web.php`.

### `GET /checkout/handoff/{lead:uuid}`

Server-rendered Blade page. The only public server-rendered page in the application. Route model binding resolves a `Lead` by its UUID. Builds the embed payload via `PrxEmbedPayloadBuilder` and renders `resources/views/pages/checkout/handoff.blade.php`.

### `POST /api/internal/checkout/embed-complete`

**Controller:** `App\Http\Controllers\PrescribeRx\EmbedCompleteController`

Advisory ping fired by the prescribe-rx embed's `onComplete` callback. Not authoritative — data is client-supplied and unverified. Calls `MarkLeadHandedOffAction` to flip the Lead status immediately for snappy UX. The signed webhook is the source of truth.

Accepts: `{ lead_uuid, encounter_id?, patient_id? }`. Always returns `{ "ok": true }` even for unknown leads (fails open to avoid blocking the patient's thank-you page).

### `POST /api/webhooks/prescribe-rx`

**Controller:** `App\Http\Controllers\PrescribeRx\WebhookController`

**Middleware:** `App\Http\Middleware\VerifyPrescribeRxSignature` — verifies `X-PrescribeRx-Signature` HMAC using `IntegrationSettings::$prescribe_rx_webhook_secret`. CSRF-exempt (set in `bootstrap/app.php`).

Event router dispatches by `payload.event`:

| Event prefix | Handler | Effect |
|---|---|---|
| `encounter.*` | `handleEncounter()` | Upserts encounter via `UpsertEncounterAction`; cross-updates Lead status |
| `order.*` | `handleOrder()` | Upserts order via `UpsertOrderAction` |
| `shipment.*` | `handleShipment()` | Upserts shipment via `UpsertShipmentAction` |

All handlers are idempotent (webhook delivery is at-least-once). Exceptions are caught and logged; the endpoint always returns 200 to prevent prescribe-rx retry storms.

`scrubMetadata()` strips clinical keys (`allergies`, `medications`, `answers`, `vitals`, etc.) from any metadata pass-through as defense in depth — this application never persists clinical data.

---

## Integration points

| Module | How intake connects |
|---|---|
| **Catalog** | `IntakeSchemaController` reads `products`, `packages`, `categories` to resolve `provider_encounter_type_id` |
| **Leads** | `PrxEmbedPayloadBuilder` reads Lead demographics and `cart_items`; `EmbedCompleteController` and `WebhookController` write Lead status via actions |
| **Commerce (Encounters/Orders/Shipments)** | `WebhookController` creates/updates records via `UpsertEncounterAction`, `UpsertOrderAction`, `UpsertShipmentAction` |
| **Settings** | `IntegrationSettings` supplies all credentials; `TelehealthManager` reads `prescribe_rx_enabled` to select the provider |

---

## Configuration reference

`config/prescribe-rx.php`:

| Key | Env var | Default | Description |
|---|---|---|---|
| `urls.sandbox` | `PRESCRIBE_RX_SANDBOX_URL` | `https://demo.prescribe-rx.com/api/v1` | Sandbox base URL |
| `urls.production` | `PRESCRIBE_RX_PRODUCTION_URL` | `https://prescribe-rx.com/api/v1` | Production base URL |
| `http.connect_timeout` | `PRESCRIBE_RX_CONNECT_TIMEOUT` | `5` | Seconds |
| `http.request_timeout` | `PRESCRIBE_RX_REQUEST_TIMEOUT` | `30` | Seconds (generous for unified intake) |
| `http.retry_times` | `PRESCRIBE_RX_RETRY_TIMES` | `2` | HTTP retry attempts on failure |
| `http.retry_sleep_ms` | `PRESCRIBE_RX_RETRY_SLEEP_MS` | `200` | Sleep between retries |
| `stub` | `PRESCRIBE_RX_STUB` | `false` | Return canned responses, no real HTTP calls |
| `embed.skip_steps` | — | `['product-selection', 'demographics']` | Step slugs passed to embed SDK to skip pre-filled steps |

Per-tenant secrets are in `IntegrationSettings` (encrypted at rest via spatie/laravel-settings):
- `prescribe_rx_api_token`
- `prescribe_rx_embed_code`
- `prescribe_rx_webhook_secret`

---

## Gotchas and non-obvious decisions

**`filter[email]` is a fuzzy search.** `Client::findPatientByEmail()` uses `GET /patients?filter[email]=X` which performs a LIKE query on the PRX side. The method does an exact case-insensitive match on returned rows to compensate. A canonical exact-match endpoint is pending on the PRX side.

**`PrescribeRxProvider::http()` vs `Client` methods.** `PrescribeRxProvider` has two ways to call the PRX API: delegating to the injected `Client` (which calls `extractData()` and throws `PrescribeRxException` on non-2xx), and the internal `http()` helper (raw `PendingRequest`, returns raw responses without throwing). Methods not yet extracted into `Client` use `http()`. When adding new PRX API calls, prefer extracting them into `Client` so error handling is consistent.

**Embed payload emits two address shapes.** `PrxEmbedPayloadBuilder::buildPrefill()` emits both flat keys (`address_line1`, `city`, etc.) and a nested `address` object. Different schema versions of the PRX embed SDK accept different shapes. This is safe — the embed ignores keys it does not recognize.

**Skip steps are encounter-type-specific.** The slugs in `config/prescribe-rx.php → embed.skip_steps` are valid only for the encounter types they were discovered on. Unknown slugs are silently ignored by the SDK, so accumulating slugs from multiple encounter types is harmless. To find slugs for a new encounter type: check the schema response from `GET /telehealth/encounter-types/{id}/schema → steps[*].slug`, or browse `demo.prescribe-rx.com/admin/encounter/types/{id}/edit`.

**Patient portal auth is not yet available.** `PrescribeRxProvider::supportsPatientPortalAuth()` returns `false` and `issuePatientToken()` returns `null`. The `Client::issuePatientToken()` method is implemented and tested against the stub, but the PRX-side endpoint has not been provisioned for this sales org yet. When it becomes available, flip the flag in `PrescribeRxProvider`.

**Webhook secret storage.** The `prescribe_rx_webhook_secret` is encrypted at rest. When rotating it: update the secret in the PRX admin first, then update the value in Settings → Integrations immediately after — there is a narrow window between rotation and update where webhooks will fail verification. PRX retries failed webhooks, so no events will be permanently lost.

**`TelehealthManager` is lazily resolved per-request.** The resolved provider instance is cached in `$this->resolved` for the lifetime of the request. If you need to force a specific provider in tests, resolve `TelehealthManager` from the container and call `->provider('prescribe-rx')` directly, or mock the interface.


## Anonymous patient registration identity boundary (2026-09-06)

`RegisterPatientAction` creates only a local, unverified patient. It never calls `findPatientByEmail` and never sets `prx_patient_chart_id`, `prx_patient_id`, `prx_chart_verified_at`, or `email_verified_at`. Client-supplied values for those fields are ignored. The returned local Sanctum token is not proof of PRX chart ownership. Linking happens only through the claim flow (`POST /patient/claim-links` → emailed single-use link → `POST /patient/claim`), which requires the order's encounter AND proof of the order's mailbox; an exact email match is insufficient. See `docs/portal/dev.md`. `PatientAuthTest` asserts no lookup/outbound request and no chart linkage or verification at registration.
