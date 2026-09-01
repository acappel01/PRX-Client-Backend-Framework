# Leads Module — Developer Guide

**Status:** Shipped 2026-06-28

## Overview

The Leads module captures visitor intent at the start of a checkout flow. A lead record is created when the storefront submits the first checkout step (contact details + cart snapshot). The same record is updated as the visitor progresses through the prescribe-rx embed handoff and, ultimately, encounter completion.

Leads are the local tracking anchor for a checkout attempt. They carry enough state to:

- Pre-fill the checkout form on a return visit (recovery email → UUID lookup).
- Pass prefill data into the prescribe-rx embed (address, DOB, gender).
- Record cart contents and subtotal at capture time for abandonment analysis.
- Store UTM/referrer attribution for marketing reporting.
- Track PRX encounter/patient IDs and timestamps for reconciliation.

---

## Data Model

### `leads` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | Auto-increment. |
| `uuid` | uuid, unique | Public identifier. Route model binding key. Used by the frontend for prefill lookups. |
| `cart_ulid` | string(26), nullable | ULID of the anonymous cart session that created this lead. Used at checkout to prevent cross-session cart/lead substitution. Added 2026-06-27. |
| `patient_id` | FK → patients, nullable | Linked patient record once a patient exists. Added 2026-06-28. |
| `status` | string(32) | Cast to `LeadStatus` enum. Default: `new`. Indexed. |
| `first_name` | string | Required. |
| `last_name` | string | Required. |
| `email` | string | Required. Indexed. |
| `phone` | string, nullable | |
| `date_of_birth` | date, nullable | |
| `gender` | string(32), nullable | Stored free-form. The provider accepts only `male` / `female` / `other`, so an unmappable value (e.g. `prefer_not_to_say`) is DROPPED at the intake boundary rather than guessed. |
| `address_line1` | string, nullable | **This is the SHIPPING address.** |
| `address_line2` | string, nullable | Sent as `street2`, never concatenated into `street`. |
| `city` | string, nullable | |
| `state` | string(8), nullable | 2-letter code. **Decides which licensed clinician can take the encounter**, so it is the most consequential field on the record. |
| `postal_code` | string(16), nullable | |
| `country` | string(2) | Default `US`. |
| `billing_same_as_shipping` | boolean | Default true — the common case, and the behaviour before billing was collected. |
| `billing_address_line1` | string, nullable | Required by the API only when `billing_same_as_shipping` is false. |
| `billing_address_line2` | string, nullable | |
| `billing_city` | string, nullable | Required when billing differs. |
| `billing_state` | string(2), nullable | Required when billing differs. |
| `billing_postal_code` | string(16), nullable | Required when billing differs. |
| `billing_country` | string(2), nullable | |

**Why the shipping address has no `shipping_` prefix.** These columns predate
billing and are part of the public `POST /leads` contract, so renaming them
would break every existing consumer for a naming gain. They are the shipping
address; the prefixed set is billing. A partial billing address is rejected at
the endpoint rather than assembled, because the provider 422s the whole intake
on an incomplete one.

`date_of_birth` is validated `before:-18 years` here as well as by the
provider's own preclusion rule.
| `sms_consent` | boolean | Default false. |
| `email_consent` | boolean | Default false. |
| `consent_given_at` | timestamp, nullable | Set automatically when either consent flag is true at creation time. |
| `cart_items` | json, nullable | Array of `CartItemData`-shaped objects. Snapshot locked at capture time. |
| `cart_subtotal` | decimal(10,2), nullable | Cart subtotal in USD at capture time. |
| `checkout_path` | string(32), nullable | Cast to `CheckoutPath` enum. `local` or `prx`. |
| `utm_source` | string, nullable | |
| `utm_medium` | string, nullable | |
| `utm_campaign` | string, nullable | |
| `utm_term` | string, nullable | |
| `utm_content` | string, nullable | |
| `referrer` | string(2048), nullable | HTTP referrer URL. |
| `landing_url` | string(2048), nullable | Landing page URL. |
| `user_agent` | string(512), nullable | Truncated to 512 chars. |
| `ip_address` | string(45), nullable | IPv4 or IPv6. |
| `prescribe_rx_encounter_id` | string, nullable | UUID of the PRX encounter. Indexed. Populated by `MarkLeadHandedOffAction`. |
| `prescribe_rx_patient_id` | string, nullable | UUID of the PRX patient. Indexed. Populated by `MarkLeadHandedOffAction`. |
| `handed_off_at` | timestamp, nullable | When the lead was handed off to PRX. |
| `completed_at` | timestamp, nullable | When the encounter was completed. |
| `prescribe_rx_response` | json, nullable | Last raw response payload from PRX for debugging. Populated by `MarkLeadCompletedAction`. |
| `notes` | text, nullable | Internal operator notes. |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp, nullable | Soft delete. |

### Dispositions (`lead_dispositions`)

`leads.status` holds a **slug string**, and the row describing that slug lives in
`lead_dispositions`. It is **not a foreign key** — matching on slug meant the column
kept its existing values, the API wire format did not move, and no data migration was
needed. `Lead::disposition()` is a `belongsTo` on `status` → `slug`.

| Column | Notes |
|---|---|
| `slug` | Unique. What lands in `leads.status` and what workflows match on. |
| `name` / `description` / `color` | Operator-facing. `color` is a Filament colour name, never a hex. |
| `is_default` | The stage new leads start on. Exactly one row, enforced in `LeadDisposition::saving`. |
| `is_system` | A slug application code writes literally. Never deletable, never re-sluggable. |
| `is_active` / `sort_order` | Picker visibility and ordering. |

**The trade for slug-matching is referential integrity, bought back in the model.**
`LeadDisposition` throws on: deleting a system row, re-slugging a system row, and
deleting or re-slugging any row that leads reference (`leadsUsing()`). This mirrors the
palette-colour guard, for the same reason — when the reference is by name, **a rename is
a removal**, and the failure is silent (leads render a raw slug).

`LeadDisposition::map()` memoises slug → name/colour for the life of the request, so a
lead table renders badges without an N+1. Call `forgetMap()` after mutating rows.

**`App\Enums\LeadStatus` still exists** and is now narrower: it is the set of slugs the
code itself writes (`MarkLeadHandedOffAction` writes `handed_off`). The migration seeds
one `is_system` row per case. It is **not** an Eloquent cast any more — `leads.status`
casts to `string`, because an operator-added slug would throw a `ValueError` on read.

| Seeded slug | Label | Badge color |
|---|---|---|
| `new` | New | gray |
| `handed_off` | Handed off to PRX | warning (yellow) |
| `completed` | Completed | success (green) |
| `abandoned` | Abandoned | danger (red) |

### Consent audit (`lead_consents`)

**Append-only, enforced in the model** — `updating` and `deleting` both throw. A
withdrawal is a new row with `granted = false`; a correction is a new row. There is no
`updated_at` column.

> **Know the limit of that guarantee.** These are Eloquent *model* events, so they cover
> every path through a model instance and nothing else. `LeadConsent::query()->update()`,
> `DB::table('lead_consents')->delete()` and `withoutEvents()` all bypass them, because
> query-builder bulk operations never fire model events. Real enforcement would need a
> database trigger or revoked `UPDATE`/`DELETE` privileges. The honest claim is "no path
> through the model", not "impossible" — do not build on the stronger one.

`leads.email_consent` / `sms_consent` / `consent_given_at` remain the current-state
summary and are kept in step by `RecordConsentAction`, which is the single writer. The
audit row is the source of truth; the booleans are a cache of its latest value per
channel.

| Column | Notes |
|---|---|
| `channel` | `email` \| `sms`. A string, not an enum column, so a new channel needs no migration. |
| `granted` | `false` is a withdrawal or a decline — which is why this is not an "opt-ins" table. |
| `consent_text` | **The sentence the visitor actually saw.** Null means genuinely unknown, never "none was shown" — currently backfilled rows *and* checkout leads, since the checkout frontend still hardcodes its labels and sends no `consent_disclosures`. The quiz sends them. |
| `consent_version` | Free-form: a semver, a date, or a hash of the surrounding legal copy. |
| `source` | `quiz` \| `checkout` \| `admin` \| `api` \| `backfill`. |
| `ip_address` / `user_agent` | **Server-derived at capture**, never taken from the payload. |
| `recorded_by_user_id` | Set when an operator recorded it, so that is never confusable with a visitor's own act. |

**Why the text is snapshotted:** the consent sentence is operator copy living in
`quiz_questions.config`, editable at any time. Without a snapshot, editing that wording
silently rewrites the meaning of every consent already given. A consent whose wording you
cannot reproduce is not evidence.

**The client supplies the wording, and that is deliberate.** Only the client knows what it
rendered. It arrives as `consent_disclosures` and is stored as *descriptive evidence*
beside server-derived IP/UA — it is never treated as authorisation. The booleans still
decide whether consent was given.

**A declined channel is recorded when its wording was shown**, and nothing is recorded for
a channel the payload was silent about. "Declined" and "never asked" are different facts
and the backend does not guess between them.

The migration backfills existing consents with `source = 'backfill'` and
`consent_text = null`. Writing today's wording onto a consent given last month would
manufacture evidence; the gap is recorded honestly instead.

### Events

| Event | Fired from | Fires for |
|---|---|---|
| `Leads\LeadCreated` | `CreateLeadAction`, **after** the transaction commits | **Every** lead, quiz or checkout |
| `Leads\LeadDispositionChanged` | `LeadObserver::updated` | Any change of `leads.status`, by any write path |
| `Quiz\QuizCompleted` | `LeadController`, guarded by `$quiz !== null` | Quiz leads only |

**`LeadCreated` exists because `QuizCompleted` does not fire for checkout leads.** Hanging
welcome comms off `QuizCompleted` silently skips the highest-intent leads the funnel
produces. `QuizCompleted` fires *in addition*, never instead.

**`LeadDispositionChanged` reads its `from` from a pre-write snapshot, not from
`getOriginal()`.** The observer is `$afterCommit`, and a commit callback runs after
`syncOriginal()` — so `getOriginal('status')` there returns the NEW value, `$from === $to`,
and the guard swallows the event. That shipped: transactional status writes, including
`SubmitPrescribeRxCheckoutAction`'s move to `handed_off`, dispatched nothing whatsoever
while direct writes worked. See `App\Support\ModelChangeSnapshot` and the regression test
`test_the_event_fires_for_a_write_inside_a_transaction`.

**`LeadDispositionChanged` carries both `from` and `to`,** because the useful workflow
conditions are transitions, not states — "became `quiz_complete` *from* `new*`" cannot be
reconstructed after the fact. It lives in an observer rather than in each action because
there are already five write paths and workflow actions will be a sixth; a funnel that
reacts to four of six transitions is worse than one that reacts to none, because the gap
is invisible.

**`App\Enums\CheckoutPath`**

| Value | Label |
|---|---|
| `local` | Local checkout (NMI / Authorize.net) |
| `prx` | PRX embed |

### `CartItemData` shape (stored in `cart_items` JSON)

```json
{
  "resource_type": "product",
  "resource_id": 42,
  "quantity": 1,
  "name": "Testosterone Cypionate",
  "unit_price": 149.00,
  "price_suffix": "/mo",
  "billing_period": "monthly",
  "prescribe_rx_id": "uuid-from-prx",
  "prescribe_rx_number": "PRX-001"
}
```

`resource_type` is one of `product`, `package`, or `plan`. The `prescribe_rx_id` and `prescribe_rx_number` fields map the local catalog item to its prescribe-rx counterpart for use at intake submission time.

### Relationships

```
leads -> hasMany -> encounters (App\Models\Commerce\Encounter)
leads -> belongsTo -> patients (App\Models\Patient, nullable)
```

---

## DTOs

### `App\Data\Leads\LeadData`

Input DTO used by `CreateLeadAction`. All fields mirror the `leads` table. `cart_items` is typed as `DataCollection<int, CartItemData>|array<int, CartItemData>`. `checkout_path` defaults to `CheckoutPath::PrescribeRx`.

### `App\Data\Leads\CartItemData`

Nested DTO for items inside `cart_items`. `resource_type` is validated to be one of `product`, `package`, `plan`. `quantity` minimum is 1.

---

## Actions

### `App\Actions\Leads\CreateLeadAction`

Creates a new `Lead` inside a database transaction. Accepts a `LeadData` DTO. Serializes `cart_items` from either a `DataCollection` or a plain array. Sets `status` to `LeadStatus::New_` unconditionally. Sets `consent_given_at` to now if either consent flag is true.

Used by `LeadController::store` (builds `LeadData` from the validated request + `X-Cart-Token` header) and available to admin-side Livewire flows.

### `App\Actions\Leads\MarkLeadHandedOffAction`

Transitions a lead to `LeadStatus::HandedOff`. Accepts the `Lead` model and optional `$encounterId` and `$patientId` strings. Sets `handed_off_at` to now. If IDs are not passed, the existing values on the model are preserved. Wrapped in a transaction.

### `App\Actions\Leads\MarkLeadCompletedAction`

Transitions a lead to `LeadStatus::Completed`. Accepts the `Lead` model and an optional `$response` array (raw PRX API response). Sets `completed_at` to now and stores the response in `prescribe_rx_response`. Returns a fresh model instance. Wrapped in a transaction.

---

## API Endpoints

Both endpoints are **unauthenticated** (public). No Sanctum token required.

### `POST /api/v1/leads`

Creates a new lead. Called by the frontend when the visitor submits the first checkout step.

**Request body (JSON):**

| Field | Type | Required | Notes |
|---|---|---|---|
| `first_name` | string | Yes | Max 100. |
| `last_name` | string | Yes | Max 100. |
| `email` | string | Yes | Valid email, max 255. |
| `phone` | string | No | Max 30. |
| `date_of_birth` | date | No | Must be at least 18 years ago. |
| `gender` | string | No | One of: `male`, `female`, `other`, `prefer_not_to_say`. |
| `address_line1` | string | No | Max 255. |
| `address_line2` | string | No | Max 255. |
| `city` | string | No | Max 100. |
| `state` | string | No | Max 8. |
| `postal_code` | string | No | Max 16. |
| `country` | string | No | ISO 3166-1 alpha-2, size 2. |
| `sms_consent` | boolean | No | Default false. |
| `email_consent` | boolean | No | Default false. |
| `checkout_path` | string | No | `local` or `prx`. |
| `cart_items` | array | No | Array of cart item objects. |
| `cart_subtotal` | numeric | No | Min 0. |
| `utm_source` | string | No | Max 255. |
| `utm_medium` | string | No | Max 255. |
| `utm_campaign` | string | No | Max 255. |
| `utm_term` | string | No | Max 255. |
| `utm_content` | string | No | Max 255. |
| `referrer` | url | No | Max 2048. |
| `landing_url` | url | No | Max 2048. |

**Headers:**

| Header | Notes |
|---|---|
| `X-Cart-Token` | Optional ULID of the anonymous cart session. Stored as `cart_ulid` to prevent cross-session pairings at checkout. |

The controller also captures `ip_address` from the request and `user_agent` from the `User-Agent` header automatically — do not pass these in the body.

**Response: 201 Created**

```json
{
  "data": {
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "status": "new",
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com",
    "phone": null,
    "date_of_birth": null,
    "gender": null,
    "address": {
      "line1": null,
      "line2": null,
      "city": null,
      "state": null,
      "postal_code": null,
      "country": "US"
    },
    "consents": {
      "sms": false,
      "email": false,
      "given_at": null
    },
    "checkout_path": "prx",
    "handoff_url": "https://example-install.com/checkout/handoff/550e8400-e29b-41d4-a716-446655440000",
    "cart_items": [],
    "cart_subtotal": null,
    "handed_off_at": null,
    "completed_at": null
  }
}
```

`handoff_url` is the absolute URL of the server-rendered Prescribe-Rx embed handoff page. When the configured checkout path is `prx`, the frontend redirects the browser here immediately after lead creation — see `docs/checkout/dev.md`. The URL host follows the request host (the API base the frontend called), so it is correct in every environment without configuration.

**Response: 422 Unprocessable Entity** — standard Laravel validation error envelope.

---

### `GET /api/v1/leads/{uuid}`

Retrieves a lead by its UUID. The UUID acts as a bearer credential — the frontend uses it from a recovery email link to pre-fill the checkout form.

**Route binding:** `{lead}` is resolved by `uuid` column (`getRouteKeyName` returns `'uuid'`).

**Response: 200 OK** — same shape as the `POST` response body.

**Response: 404 Not Found** — if no lead exists with that UUID.

**Security note:** This endpoint is unauthenticated. The UUID is the only access control. Do not expose UUIDs in publicly enumerable locations (e.g., sitemaps, public logs). Share only in recovery email links addressed to the lead owner.

---

## Filament Admin

The Filament resource lives at `App\Filament\Resources\Leads\LeadResource`. Navigation group: **Leads**, sort order 10.

- **No create page.** Leads are created by the public API only. `ListLeads::getHeaderActions()` returns an empty array.
- **Edit page** supports soft delete (`DeleteAction`), force delete (`ForceDeleteAction`), and restore (`RestoreAction`).
- **Bulk actions** on the list: soft delete, force delete, restore.
- Route model binding uses `getRecordRouteBindingEloquentQuery` with `SoftDeletingScope` removed, so soft-deleted records can still be accessed directly via URL.

The `LeadResource::$recordTitleAttribute` is `email`, so the breadcrumb and page title show the lead's email address.

---

## Integration Points

### prescribe-rx Handoff

When the checkout flow hands a lead off to the PRX embed, call `MarkLeadHandedOffAction::execute($lead, $encounterId, $patientId)`. This populates `prescribe_rx_encounter_id`, `prescribe_rx_patient_id`, and `handed_off_at`, and sets status to `handed_off`.

When the PRX embed completes and a webhook or confirmation callback is received, call `MarkLeadCompletedAction::execute($lead, $responsePayload)`. This sets status to `completed`, stamps `completed_at`, and stores the raw response for debugging.

### Cart Module

The `cart_ulid` column links a lead to the anonymous cart that was active when the lead was created. At checkout, verify that the lead's `cart_ulid` matches the `X-Cart-Token` header to prevent a visitor from substituting a different cart for another visitor's lead.

### Encounters

`Lead::encounters()` returns the `hasMany` relationship to `App\Models\Commerce\Encounter`. Encounters are the clinical records created downstream in prescribe-rx and synced locally.

### Patients

`leads.patient_id` is a nullable FK to `patients`. It is populated once a patient record is matched or created after PRX handoff. Nulls on delete.

---

## Gotchas and Design Decisions

### UUID as route key and access credential

The UUID is both the route key for model binding and the only credential guarding the `GET /api/v1/leads/{uuid}` endpoint. There is no authentication on this endpoint by design — recovery email links must work without requiring the visitor to log in. Keep UUIDs out of server logs that are accessible to unauthorized parties.

### date_of_birth minimum age validation

The `store` endpoint validates `date_of_birth` as `before:-18 years`, enforcing that the lead is at least 18 years old. This is a telehealth compliance requirement.

### gender is free-form in the database

The `gender` column accepts any string up to 32 chars. The API validates it against a fixed set (`male`, `female`, `other`, `prefer_not_to_say`), but the column was designed this way to stay flexible for PRX embed prefill, which may accept alternative values in the future.

### Cart snapshot is immutable after capture

`cart_items` and `cart_subtotal` are written once at lead creation and are never updated by the system. They reflect what the visitor selected at the moment they submitted the first checkout step, even if the live catalog changes afterward.

### Soft deletes on admin list

The `TrashedFilter` in `LeadsTable` lets operators see trashed records. The route model binding query removes the soft-delete scope so trashed leads remain accessible by UUID from the edit URL. This is intentional — operators need to be able to view and restore leads that were accidentally deleted.
