# Patient portal proxy

The admin's half of the white-label patient portal. The portal browser talks only to the portal's
own origin; that app talks only to this API; this API talks to the clinical provider. **One
credential boundary, one audit point.** The browser never holds a provider token, and the portal
never holds one either.

Routes live under `/api/v1/patient/*` (`routes/api.php`), behind
`no-store` + `auth:sanctum` + `patient` + `throttle:api`.

## Two rules

**1 · Nothing leaves without passing `PortalResponseFilter`.**

Two provider endpoints return raw Eloquent models — `$paginator->items()` straight into the JSON
envelope, with no `$hidden`, no resource class. The wire payload is the whole table, and the table
grows. Returning what the provider returned ships a live `video_room_token` and the order's
`profit_margin` to a browser.

It is an **allowlist**, not a denylist, and it **fails closed**: a screen with no spec throws
rather than passing the payload through, so forgetting a spec is never the silent default.

**2 · No identifier from the request body is ever forwarded.**

Every id sent onward is read from the session's own `Patient` record, or proven to belong to it
first. This matters most on the two **scheduling** endpoints, which authenticate with the
sales-org token: the provider gates those on the *caller's* tenancy, and the caller is the whole
organisation, so its ownership check cannot tell one of our patients from another. Forwarding a
body straight through there is not a leak — it is a cross-patient **write**.

`storeVital` is the one endpoint that forwards the request body wholesale. It carries no id (the
provider takes the chart from the token and discards unknown keys). Anything that grows an id
there must be validated here first.

## Linking a patient to their chart

An account with no `prx_patient_chart_id` sees nothing: `IssuePortalTokenAction`
refuses to mint without one. Until 2026-09-08 the **only runtime writer of that
column was a text input on the Filament patient form**, so every real patient
needed an operator to paste a chart id by hand.

Linking now takes **two requests with an email between them**, and it needs two
separate proofs:

| Proof | Answers | Comes from |
|---|---|---|
| The order's **encounter** | "this order is real and created this chart" | a row only our server or a signed webhook can write |
| A single-use **link sent to the order's mailbox** | "the person signed in placed this order" | the patient receiving and acting on our email |

```
POST /patient/claim-links   (session, empty body)
   → RequestClaimLinkAction  → email to the ORDER's address: {portal}/claim/{token}
POST /patient/claim         (session, { token })
   → ClaimPatientRecordAction → LinkPatientToPrxChartAction  (inside one transaction)
```

`LinkPatientToPrxChartAction` is still the single place a chart is linked, and
`ClaimPatientRecordAction` is its only caller. **Do not expose it to a request
directly again** — see the history below.

### The evidence is the ENCOUNTER. A lead proves nothing.

**The obvious implementation is the vulnerable one, and it was written and
caught in review before it shipped.** That version resolved the chart from the
lead's *email* against the provider. It is a complete account takeover, because
**`POST /leads` is anonymous and returns the uuid** — it has to be, it is the
checkout form itself:

1. register with the victim's address — registration verifies nothing;
2. `POST /leads` with that address, read the uuid out of the 201;
3. claim it — emails match, lead unclaimed, provider resolves the address to the
   victim's chart. Full portal on their clinical record.

So anyone can mint a lead for any address. What cannot be minted is an
**`Encounter`**, whose only two writers take no identifier from an untrusted
caller:

| Writer | Why it is trustworthy |
|---|---|
| `SubmitPrescribeRxCheckoutAction` | our server called the provider and read `patient_chart_id` out of its response |
| `UpsertEncounterAction`, only from `PrescribeRxWebhookController` | HMAC-verified, `hash_equals` on `X-PRX-Signature` |

The chart id comes from there and nowhere else. **`leads.prescribe_rx_patient_id`
is deliberately ignored** — it has four writers, and two of them
(`LeadIntakeController::complete`, `EmbedCompleteController`) take it from the
request body with no credential. A disagreement is logged; the encounter wins.

### The mailbox is the second proof

The encounter removed an attacker's ability to *manufacture* an order. It did not
remove the value of a *leaked* one: the first shipped endpoint,
`POST /patient/link-chart`, took an order uuid and checked it against the
account's asserted address, so someone who knew a customer's email and held the
uuid of their real, unclaimed order could still claim it. The uuid rides in the
intake URL, so it leaks.

That endpoint is **removed**, not kept alongside. Its checks were a strict subset
of the new flow's, so leaving it reachable would have left the unverified path
open. `RequestClaimLinkTest::test_the_old_uuid_endpoint_is_gone` pins the 404.

**Requesting a link** (`RequestClaimLinkAction`):

- **The caller supplies nothing.** No uuid, no address: the eligible order is the
  newest lead whose email equals the session's (case-insensitive), with
  `patient_id` null and an encounter carrying a chart id. The uuid stops
  travelling.
- **The answer is a uniform 202** whether or not an order matched. Anything else
  tells a person who registered a stranger's address whether that stranger has a
  completed consultation.
- **"Can this install send at all" is asked BEFORE eligibility**, so a 503
  describes the installation, never the order: `PATIENT_PORTAL_URL` set, exactly
  one active integration offering `transactional_email`, and its `test()` passing
  (for the site mailer that is `email_enabled` plus a real transport).
- One live link per order — a new request deletes the outstanding one.
- **Sent synchronously**, so the plain token never lands in a queue payload and a
  failed send is reported (503, token deleted) rather than promised.
- Limited to 3 an hour and 10 a day **per account** (`claim-link`): the account is
  what chooses the recipient, so the limit protects the mailbox as much as us.

**Using a link** (`ClaimPatientRecordAction`):

- **Bound to the ORDER and the ADDRESS, not the requester.** The session's address
  must equal `sent_to`. `patient_id` on the token row is audit only.
- **The variant that must never be built** is "consume the link and attach the
  record to whichever account holds that address", with no session. An attacker
  who registered a victim's address requests a link; the victim clicks an email
  that genuinely came from us; the victim's record lands on the attacker's
  account. Requiring the clicker's own session means a click can only ever land
  on an account the clicker can sign in to.
- **One refusal for every token failure** — unknown, malformed, used, expired,
  sent to a different address, order deleted: `errors.token[0]` is byte-identical.
  Checked before any write, so a mismatched session never spends somebody else's
  link.
- **Consumption is a conditional UPDATE by primary key**
  (`consumed_at IS NULL AND expires_at > now`) and exactly one affected row is the
  proof this request won. A PK equality on an existing row takes a record lock
  only, so no gap lock (see Guards). The lookup before it is non-locking. The race
  is pinned deterministically by
  `test_a_link_spent_between_the_read_and_the_write_is_refused`, which spends the
  row from a `retrieved` hook between the read and the write.
- **Linking runs inside the same transaction.** A refusal — record held by
  another account, no chart back from the provider yet — rolls the consumption
  back, so the link still works once the problem is fixed instead of being burned
  by a failure that was not the patient's. Its sentences are kept; only the error
  key moves from `lead_uuid` to `token`.
- **`email_verified_at` is stamped only here**, inside that transaction, after the
  link succeeded. Nothing else sets it. Nothing *reads* it for authorisation yet.
- **On first verification every other Sanctum token for the patient is revoked**,
  keeping the one the request arrived on: a session opened before the address was
  proven may belong to someone who knew the password without holding the mailbox.

**The link must never be spent by a GET.** Mail scanners (Outlook Safe Links,
corporate gateways, Gmail prefetch) open links before the person does. The
portal's `/claim/{token}` page renders a button and changes nothing; only the
POST behind it consumes. There is no API route that accepts a token in a URL —
`test_there_is_no_get_route_a_mail_scanner_could_spend_a_token_on`.

### The email is system-owned, and routed like any other

The claim email carries a live credential, so it is **not** a workflow step. As
one, the token would sit in the workflow context, the queued job and the run log;
a step could route it to a webhook or a vendor; and switching the workflow off
would silently break linking. It still goes out through `CapabilityRouting`, so
**which provider delivers it is the operator's choice** exactly as it is for
`send_email`.

Everything *around* it is configurable. Three token-free events are registered as
workflow triggers on a new `patient` subject:

| Event key | Fired |
|---|---|
| `patient.claim_link_requested` | only when a link actually went out — never for a request that matched nothing |
| `patient.record_claimed` | after the claim transaction commits |
| `patient.email_verified` | on the first verification only |

🔴 **None of them carries the token, and none may.** Tests assert each event's
only property is `patient`.

The message is plain text and minimal: no product, no consultation language, no
order uuid, no chart id, no address in the URL; it names the account's creation
date so someone who did not create it can tell the link is not theirs.

🔴 **It carries nothing the account holder wrote — not even a first name.** The
email goes to the ORDER's mailbox, and the account asking may be exactly the
stranger this flow exists to stop. Greeting by the account's `first_name` let that
stranger put their own text ("your card was declined, call…") into an email from
the brand's verified domain, three times an hour. Caught by review; pinned by
`test_nothing_the_account_holder_wrote_reaches_the_order_mailbox`. Any future
personalisation must come from settings or from the order, never the account. It is sent
with `EmailMessage::$trackLinks = false`, which `LocalMailDriver` maps to
Mailgun's `o:tracking-clicks` / `o:tracking-opens` = `no` — click tracking rewrites
every link through the vendor's redirector (plain HTTP on this install's tracking
domain), and a dashboard toggle must not be able to leak a token.

### Verified live, 2026-09-12

Two staged claims on the Atlas production install (a test order under a Gmail
plus-address, with an encounter pointing at the sandbox test chart; all reverted
afterwards against a verified snapshot):

- Mailgun `accepted` → `delivered` 250 in ~2s, inbox, SPF/DKIM/DMARC pass, and the
  link in the received message was the portal URL — not rewritten by tracking.
- The token row matched the link's sha256; consumed once; `expires_at` unchanged by
  the consuming UPDATE; chart, `prx_chart_verified_at`, `email_verified_at` and
  `leads.patient_id` all written in the same second; every other session revoked.
- The plain token appeared in no Apache, portal, storefront or Laravel log, and the
  portal's sign-in and claim requests carried no Referer.
- **Found live, fixed in the portal:** opened from Gmail, the claim page saw no
  session — the portal cookie is `SameSite=Strict`, withheld on a cross-site
  navigation — and demanded a second sign-in. The portal now re-requests the page
  once from its own origin. The second live claim needed no sign-in.

### Configuration

| What | Where | Unset means |
|---|---|---|
| Portal origin for the link | `PATIENT_PORTAL_URL` (`config/portal.php`) | 503 — never a link to the admin |
| Link path | `PATIENT_PORTAL_CLAIM_PATH`, default `/claim/{token}` | — |
| Who sends | exactly one active integration instance offering `transactional_email` | 503; two or more also 503 (the choice is the operator's to make) |
| Whether the site mailer sends | Settings → Communications → email switched on, real transport | 503 |
| Link lifetime | `PatientEmailToken::CLAIM_TTL_MINUTES` (60) | — |

Tokens are stored as `sha256` in `patient_email_tokens.token_hash`; the plain value
exists only in the email. 256 bits of entropy is why a fast hash is correct and
why the lookup can be an indexed equality.

**Portals must keep the token out of logs.** Atlas's portal vhost excludes
`/claim/` request lines and any request whose Referer is a claim page from the
access log, and sends `Referrer-Policy: no-referrer` there. A `combined` log keeps
both the request line and the Referer.

### Guards

- the lead's email must equal the account's (re-checked by the link action even
  though the token already bound it — a lead's email is operator-editable);
- the lead must have an encounter carrying a chart id, or it is refused rather
  than guessed at;
- an account that already has a chart is refused — re-linking would silently
  move a patient's clinical history;
- the lead must be unclaimed. **This is checked twice on purpose**: once up
  front for a clear refusal, once inside the transaction under
  `lockForUpdate()` to close the race. A single-threaded suite cannot tell the
  two apart, so do not read the tests as pinning each separately;
- a chart already held by another account surfaces as a caught
  `UniqueConstraintViolationException`, not a pre-check. The unique index is the
  real arbiter, and **holding a lock to pre-empt it is what must not be done**:
  under `REPEATABLE-READ` a locking read on a unique index for a value that does
  not exist yet takes a **gap lock**, and two unrelated first-time claims then
  deadlock each other on the insert-intention;
- `prx_chart_verified_at` is stamped **only** in the link action. It means a
  server-side check agreed, which the Filament text input cannot say.

The token pre-check (`isUsable()`) and the conditional UPDATE are the same kind of
redundant pair: removing either alone leaves the suite green except for the race
test, which pins the UPDATE.

**A patient who checked out under a different address than they registered with
gets no link**, and that is the guard working rather than a gap. The Filament
field remains, so an operator can link that case by hand after checking who they
are.

### Known residuals

- **Registration still does not verify an address**, and `patients.email` is
  unique. Someone can register a customer's address first and squat it: they can
  never receive the link, but the real customer cannot register. Pre-existing,
  surfaced rather than solved here. The fix is account creation *by* the link.
- The confused-deputy argument relies on `patients.email` being unique and there
  being no self-serve email change. If a profile-update endpoint lands, the
  `sent_to` equality at claim is what keeps it true.
- **The 202 is uniform in body, not in latency.** A match writes a token and makes a
  synchronous provider round-trip before answering; a non-match returns after one
  SELECT. One timed request per registered address could tell them apart. Accepted
  for now because the synchronous send is what keeps the token out of a queue and
  lets a failed send be reported; closing it means answering first and sending from
  an encrypted job, at the cost of that honesty. Needs an account registered under
  the target address, and is rate-limited like every other request.
- A send that fails after `test()` passed answers 503 only for an account that has
  an eligible order. It needs a transient transport failure at that moment, and the
  account's address already belongs to the order — accepted.
- **`requested_ip` / `consumed_ip` record the portal server, not the patient.** The portal
  calls this API over its public URL, so the request's client is the portal host
  (`34.196.82.220` on Atlas, measured on the first live claim). The columns are
  honest about what they saw and useless as an audit of who. Fixing it means the
  portal forwarding the visitor's address and this app trusting that header from
  the portal only — a trusted-proxy decision of its own, not made here.
- There is no system-initiated invite yet (mail the link when a chart id first
  arrives). The table and action are shaped for it; nothing fires today.

## Endpoints

| Route | Token | Notes |
|---|---|---|
| `POST /patient/claim-links` | **none** | Account, not clinical. Empty body; uniform 202; 503 when this install cannot send; 429 over 3/hour. Emails the order's address. |
| `POST /patient/claim` | **none** | `{token}`. 200 with the patient; 422 `errors.token`. Links the record and verifies the address in one transaction. |
| `GET /patient/home` | patient | **Screen-shaped and server-ranked.** One call, not six. |
| `GET /patient/dashboard` | patient | Raw dashboard, filtered. |
| `GET /patient/encounters` | patient | Raw model upstream — heavily filtered. |
| `GET /patient/encounters/{id}/video-token` | patient | Fetched at join time, never at render time. |
| `GET|POST /patient/vitals` | patient | ⚠️ asymmetric field names — see below. |
| `GET /patient/orders` | patient | Raw model upstream — heavily filtered. |
| `GET /patient/prescriptions` | patient | ⚠️ the dose is nested under `items[]`. |
| `GET /patient/conversations` | patient | Polled; real-time is unavailable upstream. |
| `GET|POST /patient/conversations/{id}/messages` | patient | Our field is **`content`**, max 5000. |
| `GET /patient/scheduling/slots` | **sales-org** | Chart id injected from the session. |
| `POST /patient/scheduling/appointments` | **sales-org** | Encounter ownership proven first. |

## Why home is screen-shaped

The obvious way to make a clinical portal fast is to cache the reads. **A cache of clinical reads
is storing PHI**, with a retention policy nobody wrote and a revocation path nobody built — so
that lever is unavailable. The one we do have is **fan-out reduction**: compose the screen here,
close to the provider, rather than making a phone on a bad connection do six sequential
round-trips. Cache the non-PHI half (config, branding) hard; never cache anything patient-specific.

## Why ranking is here and not in the portal

The action-stack tiers, their triggers and the sort are clinical and commercial **policy**, not
presentation (`PatientActionStackService`). Two white-label deployments may legitimately disagree
about what is urgent — one surfaces lab kits aggressively, another does not — and that has to be
configuration rather than a fork of a frontend. Computing it in a browser also makes the policy
unauditable.

Four tiers: `0 Live` · `1 Blocking` · `2 Time-boxed` · `3 Routine`. Sort is tier ascending, then
soonest deadline; no deadline sorts last. **At most one tier-0 task** — the rest are demoted,
because a second live emergency steals the attention the treatment exists to command.

## Upstream failures keep their status

Every provider failure reaches us as one `PrescribeRxException`, and rendering it unhandled made
every one of them a bare `500 {"message":"Server Error"}` — measured on the live sandbox for both
a rejected weight and a provider crash, byte for byte identical. That single body is unusable by
the screen that needs it most.

`POST /patient/vitals` has two failure modes a client absolutely must tell apart:

| Upstream | What happened | What the client must do |
|---|---|---|
| **422** | The patient mistyped a value. Nothing was written. | Name the field. Let them fix it and resubmit. |
| **5xx** | P0-7 — the provider inserts the row and *then* crashes. | **Never invite a retry.** The reading is probably saved; a second one duplicates it. |

So `bootstrap/app.php` renders the exception for `api/*` requests: a 422's field-keyed `errors`
array is passed through, 403/404/409/429 keep their status with a message of ours, and everything
else becomes a **502** (or 503 when the integration is unconfigured, which surfaces as
`httpStatus: 0`).

🔴 **An upstream 401 is deliberately NOT passed through.** By the time the exception escapes,
`withPatientToken()` has already evicted the cached patient token and re-minted one with the
**org** credential, so a second 401 means the provider rejected *our* token — and the visitor is
already authenticated with us or the request never reached a controller. Answering 401 makes every
portal screen say "your session expired"; the patient signs in, that succeeds, and they land on
the same message. Rotating the provider token without updating `IntegrationSettings` would put the
whole portal in that loop. It maps to 502 with the rest of the configuration faults, and
`PortalUpstreamErrorTest` pins it.

The same reasoning applies when **choosing** a status inside `Client`: it is now a status a
patient's screen acts on, so it must describe what the CALLER should do, not where the failure was
detected. `issuePatientToken`'s "response missing token field" was a 422 for that reason and is
now a 502 — the request was fine and the provider answered 2xx with the one field missing.

**What is never passed through is the upstream MESSAGE on a 5xx.** The provider returns its own
stack in those — absolute filesystem paths, and on this endpoint the entire SQL statement with a
`patient_chart_id` inside it. `PortalUpstreamErrorTest` asserts the two outcomes have different
statuses and that none of that string survives.

Filament panel actions are unaffected: they catch the exception themselves and the handler returns
`null` for anything that is not an API request.

## Traps this module has already hit

**`no-store` must be registered before the authenticator in the middleware PRIORITY list**
(`bootstrap/app.php`), not merely listed first on the route. Laravel re-sorts route middleware and
hoists the authenticator regardless of declaration order, which left the header off every 401 and
403 — the responses that echo an id back. The anchor is the **contract**
`Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`; naming the concrete `Authenticate`
class matches nothing and silently appends to the END of the list.

**Transcribe response specs from the provider's HANDLER, never from its resource DTO.** Several
endpoints have a resource whose field names differ from the array the `/me/patient/*` handler
actually builds. Two specs shipped wrong this way and were caught in review:

- **Vitals is asymmetric.** The request takes `weight_lbs` / `blood_pressure_systolic`; the
  response returns `weight` / `systolic_bp`. Naming the request's fields in the spec stripped
  weight, height and blood pressure out of every reading while the filter reported success.
- **Prescriptions nest.** `sig`, `patient_instructions` and `titration` live under `items[]`. A
  flat spec collapsed each prescription to number and status — the "lead with the answer" rule
  failing at the data layer rather than in the markup.

`PortalFilterFidelityTest` exists because of this: it asserts the fields a screen **needs** survive
the filter. Leak tests alone are only half a contract — an empty allowlist passes every one of them.

**The ownership probe must use the PATIENT token.** `Client::findPatientEncounter` relies on the
provider's own global scope pinning a patient token to its chart, so a foreign encounter 404s.
Switching it to the org token leaves every controller test green while silently reopening the
cross-patient booking hole, so `PortalTokenContractTest` asserts the bearer on the wire. The probe
also writes a PHI-audit row upstream per call, so it belongs on a deliberate action, never a render path.

## Known residual

`provider_profile_id` and `encounter_type_id` are forwarded on the caller's word under the org
token. The provider checks only for double-booking, so a patient could post any existing provider
id — including one outside the org's pool. It is neither a leak nor a cross-patient write, and
closing it costs a second slots call per booking. **Documented rather than fixed**; revisit if the
provider adds pool validation, or if booking moves to the patient token.

## Related

- `docs/prescribe-rx/gap-register.md` — provider-side gaps.
- Frontend runbook: `atlas-protocol-web/docs/runbook/08-prx-api-parity.md` (capability matrix) and
  `09-prx-change-request.md` (the six upstream blockers).
