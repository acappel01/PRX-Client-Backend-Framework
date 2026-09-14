# Provider recovery contract investigation — September 14, 2026

## Decision

**No supported automatic no-receipt recovery or verified embed correlation contract was established.** Keep such attempts unresolved and do not resubmit intake. The existing retained-receipt finalizer remains the only qualified recovery path. An identifier webhook can authenticate a notification, but currently cannot prove which local checkout attempt created its encounter. Do not infer ownership from email, mutable metadata, browser callbacks or current integration settings.

This investigation read source only: no PRX API requests, database reads/writes, credential inspection, fetch, application boot, source edits or deployment. PRX's `AGENTS.md` was read. Paths and line numbers below refer to the inspected PRX checkout unless explicitly marked admin.

## Source provenance and deployment gap

Inspected `/var/www/html/prx-demo` HEAD `39ed32965bfa2e821403e747fc2c630015ffa476`. Existing modifications were confined to `app/Services/Protocol/Discovery/PatientDiscoveryService.php` and `TitrationPhaseBuilder.php`; neither was changed by this investigation. Cached `origin/production` was `9bb2f71596369ac205b1b30f0e7a7a6f4c4436e3`, with HEAD-only/remote-only counts **314/84**. No fetch was performed, so this is local tracking-ref evidence, not the current remote or deployed API version.

The inspected routes, unified controller, encounter controller, webhook builder and SDK differ materially from that tracking ref (442 insertions / 29 deletions across five files). Reading the tracking-ref versions also found opaque intake metadata, mutable encounter metadata, no checkout-context lookup and an SDK completion handler without origin/source checks. `git grep` of that ref found no `checkout_context`/`checkoutContext` in routes, app or SDK. Confirm the actual API/embed/worker deployment commits before treating either source snapshot as a live contract.

## API submission and recovery reads

| Evidence | Established behavior and implication |
|---|---|
| `routes/api.php:498`, `:511` | `POST /api/v1/telehealth/intake/unified` requires Sanctum plus `telehealth:read` and `telehealth:submit`. This creates intake; it is not a recovery read. |
| Admin `app/Actions/Checkout/SubmitPrescribeRxCheckoutAction.php:82` | Admin generates its attempt UUID server-side and submits `metadata.checkout_context_uuid`, `lead_uuid` and `cart_ulid` alongside explicit client/sales-org routing. This is outbound correlation data, not a provider-enforced immutable binding. |
| `app/Http/Controllers/Api/V1/Telehealth/UnifiedIntakeController.php:775`, `:1651` | Arbitrary request metadata is persisted on intake. `app/Actions/Encounter/FinalizeEncounterIntakeAction.php:1648` copies it to a newly created encounter. |
| `app/Http/Controllers/Api/V1/EncounterController.php:457` | Encounter PATCH shallow-merges caller metadata. Reserved keys do not include checkout context. A later same-tenant metadata value cannot establish original creation provenance. |
| `routes/api.php:537`, `:543`; `EncounterController.php:72` | Existing GET collection/detail APIs are encounter-oriented. Allowed collection filters are status, priority, encounter type, patient, provider and three scopes; there is no context/external-reference filter. Broad paginated scanning would not provide the required bounded, uniquely attributable read. |
| `EncounterController.php:97`, `:274` | Collection applies tenant restrictions (API/admin types can be broader); detail checks `canAccessEncounter`. Detail also writes an access audit at `:299`. Neither tenant visibility nor knowing an encounter ID proves local attempt ownership. |
| `app/Data/Encounter/EncounterResource.php:35`, `:53` | Encounter resources include clinical snapshots and metadata; they are not a dedicated minimal outcome contract. A future consumer must bound response size and project allowlisted fields without persisting clinical bodies. |
| `UnifiedIntakeController.php:63`, `:1883` | Idempotency is a tenant/user-keyed 24-hour cache replay of a previous response, accessed through the creating POST. It cannot be used as a durable, nonmutating missing-receipt lookup; an absent cache entry proceeds to creation. |
| `app/Actions/Encounter/BeginIntakeCheckoutAction.php:17`, `:42` | A separate internal payment transaction `checkout_context` exists in current HEAD. It is an intake/payment workflow context, not the admin UUID recovery endpoint or an authenticated external embed session. Do not conflate the names. |

## Outbound identifier webhook contract

`app/Services/Webhook/WebhookDispatchService.php:35` builds `{event, timestamp, data, webhook_id, subscription_id}`. A UUID is assigned **per subscription dispatch** at `:45`; it is not a globally shared business-event identity. Headers include `X-Webhook-ID`, `X-Webhook-Event`, `X-Webhook-Timestamp`; maximum delivery attempts are three (`:54`, `:63`). Tenant matching uses configured client/sales-organization/telehealth-company subscriber identities (`:120`). The envelope has no explicit environment or account-type field.

`app/Services/Webhook/PrescribeRxWebhookSigner.php:26` defines `X-PrescribeRx-Signature: sha256=<hex HMAC-SHA256>`, computed over `json_encode(payload)` with that subscription's secret. `config/webhook-server.php` selects this signer; installed `vendor/spatie/laravel-webhook-server/src/CallWebhookJob.php:189` serializes the default request body with the same `json_encode`. Thus production job verification can authenticate the **raw request bytes** and compare in constant time; do not deserialize/re-encode before verification. Signed envelope timestamp/IDs must be validated, rather than trusting independently supplied headers. The same job payload survives delivery retries; a fresh dispatch receives a different UUID. A future receiver needs durable delivery deduplication plus separate attempt-finalization uniqueness.

`WebhookPayloadBuilder.php:230` encounter-created data includes encounter/chart/type identifiers, status, creation time and `encounter_type_name`. It is not strictly identifier-only. `:217` status-change data carries identifiers/status/time. Neither emits admin `checkout_context_uuid`. `:40`/`:137` correlation uses the originating Order's external telehealth `fulfillment_notes.external_order_id` or order-mirror `metadata.external_reference`; these are different entry paths from the admin's unified-intake metadata. They must not be assumed to identify this checkout.

Authentication primitives are implementable locally, but automatic finalization is not justified. Signing confirms the subscription sent the bytes, not that mutable encounter metadata is an immutable claim to a local attempt. Source does not establish operator-provisioned receiver subscription, tenant/environment mapping, secret version, deployed wire fixtures, acceptable age/clock skew, or delayed/manual replay policy. Do not activate a receiver based on source inspection alone.

## Embed/session correlation

`public/embed/sdk.js:129` builds the iframe URL from embed code, package/products and prefill values. No server-issued checkout-context/session parameter or authenticated context exchange was found in this SDK. `:452` accepts prefixed window messages and `:497` calls `onComplete`; this handler verifies neither `event.origin` nor `event.source`. Outbound messages use `'*'` (`:482`). Completion is an untrusted browser hint even after a consumer adds origin/source validation.

`routes/web.php:2016` exposes the public embed wizard with domain middleware. `app/Livewire/Encounter/EmbeddableIntakeWizard.php:84` stores `intake_embed_config_id` in the PRX session for tenant resolution; `:344` handles the internal completion event. This is embed-configuration/session state, not a durable link from the local attempt/customer to one provider outcome. No server-to-server context creation/consumption/status API was established.

## Concrete upstream prerequisites

These are required capabilities, **not proposed routes to wire as if they exist**:

1. Agree an immutable context binding created through authenticated integration credentials, scoped to provider environment, account type, exact client/sales-org routing, local attempt and request/order fingerprint. Persist provenance before intake work; enforce unique context consumption and reject conflicting reuse. Arbitrary metadata PATCH must not replace it.
2. Supply a bounded authenticated read by that context, with minimal outcome IDs/state/time and authoritative tenant/environment/fingerprint binding. Distinguish absent, pending, uncertain, completed and conflicting results without creating intake/payment. Specify persistence lifetime and recovery after a crash before response materialization.
3. Publish an identifier-only event version carrying the immutable context/operation reference, stable transport identity and authenticated timestamp. Confirm deployed raw-body signature fixtures, subscription-to-instance mapping, key rotation, retry duration and stale/manual replay handling. If canonical reread uses an encounter ID, it must also expose the immutable binding; matching editable metadata remains insufficient.
4. Add an authenticated embed context/session creation and single-use consumption contract tied to local attempt and configured origin/tenant/environment. Return canonical correlation only through the authenticated read/event path. Fix SDK origin/source checks independently; browser completion must never establish conversion or chart ownership.
5. Provide reviewed historical evidence for null/missing bindings. No bulk inference from email or current settings; historical unresolved cases stay visible.

Once those facts are supplied, local implementation can select the provider instance from receiver configuration, authenticate and durably deduplicate envelopes, enqueue bounded canonical reads, validate frozen bindings and order fingerprints, retain only a minimal encrypted receipt and reuse `FinalizePrescribeRxCheckoutAction` with reconciliation automation suppression. Unknown/mismatched cases remain durable and visible. Isolated qualification must cover wrong tenant/environment, tampering/staleness/replay, conflicting chart ownership, API-first/webhook-first races, one finalization and zero intake/payment/workflow/marketing replays.

This block does not prevent portal qualification, coordinated-release preparation or independent local commerce work. No recovery endpoint or payment readiness is claimed by this document.
