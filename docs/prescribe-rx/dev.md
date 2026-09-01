# PrescribeRx Integration — Developer Guide

**Status:** Phase 1 shipped 2026-05-02. Foundation + 2 endpoints + 1 Action wrapper. Ready for the intake wizard to be built on top.

## Why this module exists

PrescribeRx is the canonical clinical backend — patient charts, encounters, prescriptions, orders, product catalog, lab routing, fulfillment. PrescribeRx Open Source Backend (this site) is the marketing + intake front end. The integration's job:

1. **Submit new intakes** — when a visitor finishes the intake wizard, we POST to `/telehealth/intake/unified` and prescribe-rx atomically creates the patient, encounter, and intake answers on its side.
2. **Drive dynamic intake wizards** — the questions on the wizard come from `GET /telehealth/encounter-types/{id}/schema`, so when prescribe-rx adds a question or a new encounter type, the wizard updates without a redeploy here.

Future:
3. **AI protocol generator** — Bedrock-direct (same AWS account, prescribe-rx's formulary embeddings). Described in *Future work* below.
4. **Order fulfillment + status** — when ordering routes through prescribe-rx (vs local NMI/Auth.net), poll order status + react to webhooks.

## Stack

- **Auth model**: Bearer token (Sanctum-format with `{id}|` prefix). Sales-organization tokens issued from production prescribe-rx admin. Token type 2 → ability list documented in `docs/prescribe-rx/user.md`.
- **Base URLs**: `demo.prescribe-rx.com/api/v1` (sandbox) and `prescribe-rx.com/api/v1` (production). Both already include `/api/v1`.
- **Mandatory headers**: `Authorization: Bearer {token}`, `Accept: application/json`. Without `Accept: application/json` the API returns a 302 redirect to the web login on auth failure (instead of a JSON 401), masking the real error.
- **Response envelope**: `{ success: bool, message?: string, data: object|array, meta?: object }`. Validation errors (HTTP 422) return `{ message, errors: { field: [errors] } }`.

## Architecture

Mirrors the project's mandatory layering:

```
Livewire wizard step → Action (Transacts trait → DB::transaction)
                          │
                          └─→ App\Services\PrescribeRx\Client (HTTP wrapper)
                                  │
                                  ▼
                              prescribe-rx API
                                  │
                                  ▼
                          Response DTO ← Service ← raw JSON
```

Per-tenant credentials are read from `App\Settings\IntegrationSettings` (encrypted at rest, manageable in `/admin/settings/integrations`). Static config (sandbox/prod URLs, timeouts) is in `config/prescribe-rx.php`.

## Files

```
app/
├── Settings/
│   └── IntegrationSettings.php          # spatie/laravel-settings: enabled, env, encrypted token, optional org IDs
├── Services/PrescribeRx/
│   ├── Client.php                       # Laravel HTTP-facade wrapper, retry + timeout + auth
│   └── Exceptions/
│       └── PrescribeRxException.php     # one exception type for all failure modes
├── Data/PrescribeRx/
│   ├── AddressData.php
│   ├── PatientData.php
│   ├── VitalsData.php
│   ├── MedicalHistoryData.php
│   ├── ConsentData.php
│   ├── UnifiedIntakeRequestData.php     # composes all of the above
│   ├── UnifiedIntakeResponseData.php
│   ├── EncounterTypeData.php
│   └── EncounterTypeSchemaData.php
├── Actions/PrescribeRx/
│   └── SubmitUnifiedIntakeAction.php    # DB::transaction wrapper around Client::submitUnifiedIntake
├── Filament/Pages/Settings/
│   └── ManageIntegrations.php           # /admin/settings/integrations
└── Console/Commands/PrescribeRx/
    └── Ping.php                          # `php artisan prescribe-rx:ping`

config/
└── prescribe-rx.php                     # URLs, timeouts, retry policy, stub flag

database/settings/
└── 2026_05_02_215041_create_integration_settings.php
```

## Currently implemented endpoints

| Method | Path | Service method |
|---|---|---|
| GET | `/telehealth/encounter-types` | `Client::listEncounterTypes(?$telehealthCompanyId)` |
| GET | `/telehealth/encounter-types/{id}/schema` | `Client::getEncounterTypeSchema(string $id)` |
| POST | `/telehealth/intake/unified` | `Client::submitUnifiedIntake(UnifiedIntakeRequestData $data)` (also: `SubmitUnifiedIntakeAction::execute()`) |

## How to add a new endpoint

1. Add a typed input DTO to `app/Data/PrescribeRx/` if the call mutates state (validation attributes via `spatie/laravel-data`).
2. Add a typed response DTO matching the API's `data` envelope.
3. Add a method to `Client` that calls `$this->request()->...->...`, then `$this->extractData($response)`, then `YourResponseDto::from($payload)`.
4. If the call mutates state on the prescribe-rx side AND we want to write a local row before the call (audit trail / reconciliation), wrap with an Action under `app/Actions/PrescribeRx/` using the `Transacts` trait.
5. Add a stub-mode branch in the Client method so dev environments without a token still get a typed response.
6. Update `php artisan prescribe-rx:ping` if it's a useful smoke target.

## Stub mode

Set `PRESCRIBE_RX_STUB=true` in `.env` (or `config('prescribe-rx.stub')` true) and the Client returns canned fixtures without making real HTTP calls. Useful when:

- Local dev before a sales-org token is issued
- CI / automated tests
- Demo screenshots when prescribe-rx sandbox is down

The fixtures live inline in `Client.php` so they evolve together with the response DTOs. They're keyed by example data from the OpenAPI spec.

## Configuration knobs

`config/prescribe-rx.php`:
- `urls.sandbox` / `urls.production` — base URLs (env-overridable)
- `http.connect_timeout` (5s default)
- `http.request_timeout` (30s default — unified intake is a heavyweight call)
- `http.retry_times` (2 default, 200ms sleep)
- `stub` (false default)
- `default_headers` — always includes `Accept: application/json`

## Sales-organization token guard: `payment.mode=authorize`

**Sales-org tokens cannot use `payment.mode=authorize` on the unified intake endpoint** — prescribe-rx isn't the merchant of record for API-driven flows. Use `reference_captured` (record-only, payment handled locally) or `patient_review` (deferred portal pay) instead. Embed-form tokens (browser-served, separate token type) DO support `authorize`. The Client doesn't currently send a payment block; when we add it, enforce this on our side too. The five modes are `authorize` (embed-only), `reference_preauth` (we pre-auth, they vault and capture later), `reference_captured` (we already charged — record-only audit trail), `prepaid` (settled out of band) and `patient_review` (they email the patient to confirm and pay). Collecting payment up front on our side is `reference_captured` or `reference_preauth`.

## Local Product / Package mapping

Every `Product`, `Package` and `Plan` carries provider mapping columns —
`provider_product_id` / `provider_product_sku`, `provider_package_id` /
`provider_package_sku`, `provider_plan_id` / `provider_plan_sku`. The local
catalog (custom images + marketing copy) maps to prescribe-rx UUIDs, which
decouples the marketing experience from clinical inventory and lets each
deployment expose its own slice of the catalog.

**The column prefix is `provider_*`, and only `provider_*`.** Three competing
conventions exist in this codebase (`provider_*` on catalog tables,
`prescribe_rx_*` on transactional ones, `prx_*` on patients). Reading a
`prescribe_rx_*` name off a catalog model returns **null**, because Eloquent
resolves a missing attribute to null rather than raising — that mistake sat in
the embed payload builder for months and shipped an embed with nothing
selected, silently. Assert POPULATED output when you touch that surface.

### "Sandbox" means three different things — do not conflate them

Three independent switches share the word, and only one of them is about which
server you are talking to. Reading any of them as the others produces either a
false alarm or a real test encounter on a real clinician's queue.

| Switch | Where | What it actually controls |
|---|---|---|
| `prescribe_rx_environment` | `IntegrationSettings` (ours) | **Which instance.** `sandbox` → `demo.prescribe-rx.com`, `production` → `prescribe-rx.com`. Picks the host for the API, the embed SDK and the iframe. |
| Embed **sandbox mode** | The embed config, in the provider's admin | **Whether encounters from that embed are test encounters** — on whichever instance. Exists so real clinicians are not handed test intakes. A production embed running in sandbox mode is NORMAL and is not a misconfiguration. |
| `is_sandbox` | The unified-intake payload (API path) | The API-path equivalent of the embed's mode: flags one submission as a test. Their server also auto-sets it on test-looking names. |

So a wizard served from `prescribe-rx.com` can legitimately show
"SANDBOX MODE — TEST TRANSACTION ONLY". That banner describes the embed
config's mode, not the instance, and not our environment setting.

We only ever ASSERT `is_sandbox`, never deny it — see `docs/checkout/dev.md`.

### What checkout submits

Checkout sends the **modern selection arrays**, `products[]` and `packages[]`.
Legacy flat `product_ids` is deprecated on their side and is not sent.

A package is **named, not flattened into its members**: prescribe-rx already
knows a package's contents, and keys real behaviour off the package row (labs
hold, free shipping, telehealth consult). Naming the package delegates all of
that back to the side that owns it; sending member product ids threw it away
and none of that machinery fired.

Each line carries exactly one identifier — UUID preferred, `*_number` as the
fallback — with `packages[].plan_id` selecting the term. Full resolution table,
idempotency and the `is_sandbox` / `metadata` / `gender` rules are in
`docs/checkout/dev.md`.

### Field vocabularies that differ from ours

Verified against their published field-mapping reference:

| Field | They accept | Note |
|---|---|---|
| `patient.gender` | `male` / `female` / `other` (or 1/2/3) | Our lead form also offers `prefer_not_to_say`; it is dropped, never mapped |
| `consents[].signature_method` | `click` / `typed` / `pad` | Not `keyboard` / `drawn` |
| `patient.address.street2` | its own field | Do not concatenate it into `street` |

The shipping address drives their **state-licensing** check, so it is the one
that must be structurally correct.

## Tests

Deferred (same `pdo_sqlite` / test-DB blocker as Settings + CMS modules). Targets when unblocked:

- `Client` happy-path with `Http::fake()` mocking each endpoint
- 401 / 422 / 5xx error envelopes → `PrescribeRxException` with the right `httpStatus` + `errors`
- `IntegrationSettings::encrypted()` actually encrypts the token at rest
- `SubmitUnifiedIntakeAction` rolls back on Service failure

## Future work

### AI protocol generator (Bedrock-direct)

The homepage hero has a placeholder AI Concierge widget (chat UI). The eventual real version is an AI protocol generator that suggests product / focus-area combinations based on the visitor's stated symptoms.

Important note from product owner (2026-05-02):

> The prescribe-rx side already has a Bedrock-backed protocol generator, but it returns long-form clinical protocols. The PrescribeRx Open Source Backend.com flow needs a more "suggestive" variant — click some focus areas, get back products + interaction recommendations.

Plan when this comes in scope:
- Same AWS account → use Bedrock direct (no per-call API hop through prescribe-rx)
- Bedrock has access to the prescribe-rx formulary embeddings (the prescribe-rx team manages this)
- New `App\Services\Llm\BedrockClient` + `App\Services\Llm\ProtocolSuggester` (separate service, not part of prescribe-rx)
- Prompt-engineered variant of the prescribe-rx protocol generator that returns structured `{ focus_areas[], recommended_products[], interactions[] }` instead of clinical narrative
- Livewire `SymptomConcierge` component replaces the Alpine placeholder in the hero

### Webhooks

The sales-org token has webhook abilities (`webhook:create/read/update/delete`). When intake-status updates and order-fulfillment events become important, register webhooks pointing at a `/api/webhooks/prescribe-rx` route in this app and reconcile encounter / order state locally. Defer until the Orders module ships.

### Auth flow (`/auth/login`)

Currently we use a long-lived sales-org token from the admin UI. If we ever need to rotate tokens programmatically or have user-scoped tokens, add an `App\Services\PrescribeRx\AuthClient` that wraps `POST /auth/login` and returns the token + abilities. Out of scope today.
