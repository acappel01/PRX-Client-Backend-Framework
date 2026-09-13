# Inbound webhooks — developer reference

Events pushed to this backend by an external provider. Today there is one source,
prescribe-rx; the ledger, job and replay tooling are provider-agnostic so the next source
(mail and SMS delivery events) plugs into the same path.

```
POST /api/webhooks/prescribe-rx
  → throttle:inbound-webhooks          300/min per sending IP
  → VerifyPrescribeRxSignature         401 / 503, nothing recorded
  → PrescribeRx\WebhookController      parse → RecordInboundWebhookAction → 200
        inbound_webhook_events row (status received), dedupe on a unique key
  → ProcessInboundWebhookEvent (queue: default)
        config('webhooks.handlers')[source] → PrescribeRxWebhookHandler
        → processed | unmatched | ignored | failed
```

The request only records. Acting on an event happens on the queue, so a slow or failing
handler never makes the provider time out, retry, or count a failure toward disabling the
subscription.

---

## The endpoint and its contract

| | |
|---|---|
| URL | `POST {APP_URL}/api/webhooks/prescribe-rx` (route `webhooks.prescribe-rx`, in `routes/web.php`) |
| Auth | `X-PrescribeRx-Signature: sha256=<hex HMAC-SHA256 of the raw body>`, secret `IntegrationSettings::prescribe_rx_webhook_secret` |
| CSRF | exempt via `api/webhooks/*` in `bootstrap/app.php` |
| Custom headers | none — prescribe-rx sends everything required |

| Status | When |
|---|---|
| `200 {"ok":true,"duplicate":false}` | recorded |
| `200 {"ok":true,"duplicate":true}` | a delivery already recorded (retry or replay of the same body) |
| `400` | signed, but not a JSON object or no `event` — a retry cannot help |
| `401` | signature missing, malformed or wrong |
| `503` | no webhook secret configured — fails closed |
| `500` | the row could not be written; the provider retries |

The sender treats any non-2xx as a failed delivery and disables a subscription after 50 in a
row, resetting the count on the next success. A mismatched secret therefore disables the
subscription rather than being acknowledged silently — deliberately.

`/api/v1/webhooks/prescribe-rx` no longer exists. It verified an `X-PRX-Signature` header
against a config key that was never defined, so in production it rejected everything, and its
tests passed only because the suite does not run as production.

---

## What prescribe-rx sends

From their sender (`WebhookDispatchService`, `PrescribeRxWebhookSigner`,
`WebhookPayloadBuilder`):

```json
{
  "event": "encounter.status_changed",
  "timestamp": "2026-09-13T21:29:04+00:00",
  "data": { "encounter_id": "…", "patient_chart_id": "…", "old_status": "unassigned", "new_status": "pending_provider_review" },
  "webhook_id": "uuid",
  "subscription_id": "uuid"
}
```

- `data` carries **typed ids and statuses only** — `encounter_id`, `order_id`,
  `patient_chart_id`, never `id`, `metadata`, or any reference to one of our leads.
- Order statuses are **integer-coded** (`"workflow_status": 3`); see `PrescribeRxStatusMap`.
- `webhook_id` is new per event × subscription and identical across their retries (3 tries,
  backoff 10 s then 100 s). The signature and `X-Webhook-*` headers are computed once, so a retry
  is byte-identical.
- `X-Webhook-ID`, `X-Webhook-Event` and `X-Webhook-Timestamp` are **not covered by the
  signature**. Nothing reads them; dedupe uses `webhook_id` from the signed body.
- Event families: `encounter.*`, `order.*`, `fulfillment.*` (not `shipment.*`), `lab.*`,
  `prescription.written`, `approval.*`, `subscription.*`, `appointment.*`, `patient.created`,
  and `webhook.test` from their "send test" button.

---

## The ledger — `inbound_webhook_events`

| Column | Notes |
|---|---|
| `uuid` | public handle, used by `webhooks:replay` |
| `source` | `prescribe-rx` |
| `provider_event_id` | the signed `webhook_id` |
| `event_type` | `encounter.status_changed` |
| `subject_type`, `subject_ref` | `encounter` / `order` / `lab_order` / … and the provider's id |
| `occurred_at` | the envelope `timestamp` |
| `payload_hash` | sha256 of the raw body |
| `dedupe_key` | **unique**. sha256 of `source|id|webhook_id`; without an id, of source + type + subject + timestamp + body hash |
| `payload` | the **allowlisted** `data` object (see below) |
| `status` | `received → processing → processed \| unmatched \| ignored \| failed` |
| `attempts`, `error`, `matched_type`, `matched_id`, `processed_at` | |

**`payload` is an allowlist, never the raw body.** Some events carry free text typed by clinic
staff — `reason` on `encounter.cancelled` / `order.cancelled` / `fulfillment.cancelled`, `note`
on a refill request, `failure_reason`, `provider_name` — which this install does not store.
`PrescribeRxWebhookParser::ALLOWED_KEYS` keeps ids, statuses, timestamps, amounts and tracking;
a field prescribe-rx adds later is dropped until it is added there.

**Invalid-signature requests are never recorded** — that would give an unauthenticated caller
an unbounded write path.

**Retention:** `processed`, `ignored` and `unmatched` rows are pruned after 90 days
(`InboundWebhookEvent::RETENTION_DAYS`, scheduled `model:prune` at 03:45). `failed` rows are
kept until someone deals with them.

---

## Handling — `PrescribeRxWebhookHandler`

### Update-only, and never a lead

An `Encounter` row is the evidence the patient claim flow accepts
(`Lead::scopeClaimableUnder`, `LinkPatientToPrxChartAction::chartFromEncounter`), keyed on
`encounters.lead_id`. A webhook names no lead, and `leads.prescribe_rx_encounter_id` is written by
unauthenticated endpoints (`EmbedCompleteController`, `LeadIntakeController::complete`).
Resolving a lead through that column would let anyone point their own lead at a stranger's
encounter id, wait for an event, and claim that patient's chart.

So the handler:

- finds rows **only by the provider's ids** (`prescribe_rx_encounter_id`, `prescribe_rx_order_id`);
- **updates rows our checkout created and never creates** an encounter or an order;
- **never writes `lead_id`**;
- fills `encounters.prescribe_rx_patient_id` only when it is empty, and logs a disagreement
  instead of overwriting;
- records an event for a record we do not hold as `unmatched`, and one that names no id at all
  (prescribe-rx sends `order_id: null` on fulfillment events it cannot tie to an order) as `ignored`.

The one insert is an `order_shipments` row — a tracking number under an order we already hold.
Nothing reads a shipment as proof of identity.

### Order and staleness

Each event's `timestamp` is stored as `provider_status_at`. An event older than it changes no
status, so retries, replays and out-of-order delivery cannot move a record backwards.

Rows are **found without a lock, then locked by primary key**. `SELECT … FOR UPDATE` on a unique
index for a value that is not there takes an InnoDB gap lock, and the order backfill writes that
very column into the gap — two first events for two different orders on two workers would
deadlock. The transaction makes three attempts (two retries on a deadlock) before the event is marked `failed`.
SQLite (the test suite) has no row locks, so none of this is exercised by tests.

### Per event

| Event | Effect |
|---|---|
| `encounter.created` | fills an empty `provider_status` with their starting status, stamped with their `created_at`; never moves our `status` (checkout already recorded it, and `created` can arrive after a real transition) |
| `encounter.status_changed` / `.prescribed` / `.completed` / `.cancelled` | `provider_status` ← `new_status` or the status the event implies; coarse `status` from the map; `submitted_at` / `reviewed_at` / `completed_at` / `cancelled_at` set once |
| `order.placed` / `.paid` / `.status_changed` / `.voided` | `provider_workflow_status`, `provider_payment_status`, `provider_shipping_status` (names, from integer codes); coarse `status` derived |
| `order.cancelled` | `status` cancelled, `cancelled_at` once |
| `order.refunded` | `status` refunded unless `is_partial`, `refunded_at` once |
| `fulfillment.shipped` | shipment row by `(order_id, tracking_number)`: shipped, carrier, tracking URL, `shipped_at` once; order `shipped_at` once |
| `fulfillment.delivered` | that shipment delivered, `delivered_at` once |
| `fulfillment.cancelled` | an existing shipment cancelled; never creates one |
| everything else | `ignored` (recorded, not acted on) |

**Finding the order.** By `prescribe_rx_order_id`; failing that, via `data.encounter_id` to the
encounter our checkout created and its newest order that has no provider order id yet — which
then gets the id and number, so every later event takes the direct path.
(`SubmitPrescribeRxCheckoutAction` creates the order before prescribe-rx has assigned one.)

**Whole-order shipping comes from `order.status_changed`.** A `fulfillment.*` event updates only
its shipment, because one delivered parcel of a split shipment is not a delivered order.

### Status mapping — `PrescribeRxStatusMap`

The provider's value is always stored; our coarse `status` is derived from it. **An unmapped
value leaves `status` alone** — the previous receiver defaulted unknown values to `pending` and
would have reset every encounter.

| prescribe-rx encounter status | Ours |
|---|---|
| `cart`, `pending_intake` | pending |
| `unassigned`, `intake_submitted`, `awaiting_provider`, `awaiting_scheduling`, `scheduled`, `rescheduled`, `awaiting_labs`, `labs_ordered`, `labs_received` | submitted |
| `pending_provider_review`, `provider_in_progress`, `intake_review`, `labs_in_review`, `labs_reviewed`, `in_progress`, `provider_review`, `requires_information`, `awaiting_prescription`, `awaiting_patient_review`, `on_hold` | in_review |
| `prescribed` | approved |
| `rejected` | denied |
| `completed`, `complete_no_rx` | completed |
| `cancelled`, `no_show`, `referred_out` | cancelled |

Order, most decisive first: payment `refunded` → refunded; workflow `canceled` /
`med_ineligible` / `med_denied` → cancelled; shipping `delivered` → delivered;
`partially_shipped` → partially shipped; `shipped` / `in_transit` → shipped; any active workflow
state → processing; otherwise unchanged.

### What it deliberately does not do (yet)

- **Touch leads.** No handed-off / completed transitions and no `leads.payment_status` — the
  latter fires referral commission calculation, and sandbox events would pay commissions on test
  leads. Each needs its own increment.
- **Re-read the provider.** Payloads already carry the transition; the ledger row is the
  invalidation signal a later stage can re-read from.
- **Link embed-checkout encounters.** The embed path creates no local encounter, and nothing
  carries our lead reference into prescribe-rx on that path, so its events are `unmatched`.

---

## The job — `ProcessInboundWebhookEvent`

- Resolves the handler from `config/webhooks.php` by `source`; no handler → `failed`.
- **Unmatched is retried briefly** (30 s, 2 min, 10 min): prescribe-rx sends `encounter.created`
  from inside the intake call our checkout is still waiting on, so the first attempt can
  legitimately find nothing. After that the row stays `unmatched`.
- A handler exception marks the row `failed` and is **not rethrown** — the provider was answered
  already, and a queue retry of a bug only repeats it.
- `$tries = 4` is set on the job because the default Horizon supervisor runs `tries => 1` and a
  `release()` counts as an attempt.
- **Deploying handler changes needs `php artisan horizon:terminate`** — workers keep the old
  classes and config loaded.

---

## Operating it

```bash
php artisan webhooks:replay --dry-run                     # what is unmatched
php artisan webhooks:replay                               # re-queue every unmatched event
php artisan webhooks:replay --status=failed --since="2 days ago"
php artisan webhooks:replay <uuid>                        # one event, whatever its status

php artisan prescribe-rx:register-webhook --list          # id, url and events of each subscription
```

`prescribe-rx:register-webhook` defaults to the `webhooks.prescribe-rx` route and
`encounter.* order.* fulfillment.*`. **Known gap:** their `POST /webhooks` requires
`subscriber_type` and `subscriber_id`, which `Client::registerWebhook` does not send, so creating
a subscription from the command fails; create it in the prescribe-rx admin instead.

---

## Adding a source

1. A route that verifies that provider's signature before anything else, then calls
   `RecordInboundWebhookAction::execute($source, $parsed, $rawBody)`.
2. A parser producing `ParsedInboundWebhook` — with an allowlisted payload.
3. A handler implementing `App\Contracts\Webhooks\InboundWebhookHandler`, registered in
   `config/webhooks.php`. Idempotent, and an older event must never overwrite a newer one.

---

## Tests

`tests/Feature/Webhooks/PrescribeRxWebhookReceiverTest.php` signs envelopes exactly as their
signer does. It pins: rejection records nothing; fail-closed without a secret; dedupe on the
signed id, not the header; free text dropped; update-only (no encounter, order or shipment for an
unknown id, lead untouched); unknown status leaves ours; staleness; set-once timestamps; the
order-via-encounter backfill; failed and unmatched replay; pruning keeps `failed`.
