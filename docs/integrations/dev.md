# Integrations — architecture

How this installation sends data to other people's systems, and what stops it sending the
wrong data to the wrong one.

Operator guide: [`user.md`](user.md). The engine that calls all of this:
[`../workflows/dev.md`](../workflows/dev.md).

## Which layer names a vendor

This was the load-bearing decision, and it is easy to get backwards. Vendors must be visible
and selectable — an operator has to see Klaviyo, switch it on and paste their keys. The rule
is not "never name a vendor"; it is about **which layer** does.

| Layer | Names a vendor? | Why |
|---|---|---|
| `WorkflowRegistry` action types | **No** | A `push_to_klaviyo` case in the shipped registry means every install with a different CRM forks the product to add theirs |
| `IntegrationRegistry` (driver catalogue) | **Yes** | A Klaviyo driver needs Klaviyo-specific code; it has to exist somewhere. Registered like actions are, so it is additive |
| `integration_instances` row | **Yes** | "Klaviyo — Marketing", with this operator's own credentials |
| `workflow_actions.config` | The **instance** | `{"integration": "klaviyo-marketing"}` |

So there is one generic `push_to_integration`, and the action palette is a **query against
enabled instances** rather than a list maintained in code. Enabling a vendor is what makes it
appear.

`integration_instances.provider` holds a registry **key**, never a class name. That is the
same security boundary `WorkflowRegistry` draws for the same reason: these rows are
operator-editable, and a class name in an editable row that is later instantiated is
arbitrary class instantiation in a product many companies deploy.

## Capabilities: two questions, both asked

An integration is offered for something only when **both** are true:

- **Can it?** The driver implements that capability's interface. A fact about code, checked
  with `instanceof`, and impossible to misdeclare — `IntegrationCapability::capabilitiesOf()`
  derives the set from `class_implements()`, so `registerProvider()` takes no capability list
  at all. A driver that loses an interface stops claiming the capability in the same commit.
- **May it?** The operator ticked it on the instance. One Twilio account may be authorised
  for SMS but not voice; one email vendor for transactional but not marketing.

Neither implies the other. `IntegrationRegistry::instanceOffers()` asks both so callers have
one question to remember instead of two.

| Capability | Interface |
|---|---|
| `sms` | `SendsSms` |
| `transactional_email` | `SendsTransactionalEmail` |
| `marketing_email` | `SendsMarketingEmail` |
| `crm` | `SyncsContacts` |

Transactional and marketing email are separate cases because the **consent** is separate:
somebody who gave an address to receive their own protocol has not joined a mailing list.

## Narrow interfaces, because two real vendors disagree

`TelehealthProviderInterface` in this codebase is **42 methods** and no second vendor could
implement it. That is what a `supports(string $capability): bool` design produces: one
interface wide enough for every vendor, with each implementation throwing on the half it
cannot do.

The concrete case this design had to survive:

| | Klaviyo | GoHighLevel |
|---|---|---|
| Events API | yes | **does not exist** |
| Direct automation enrolment | **forbidden by their terms** | yes |
| Grouping | list **ids** | tag **strings**; no lists |

They accomplish "start the follow-up" through opposite verbs. So Klaviyo implements
`SyncsContacts` + `TracksEvents`, GoHighLevel implements `SyncsContacts` +
`EnrollsInAutomations`, and neither pretends. `SyncsContacts::addToGroup()` takes a semantic
**name** and the instance's `settings` map it — a list id at one end, a tag at the other.

Build the second driver early rather than last. Two vendors that disagree are what prove a
contract; one vendor only proves it can describe itself.

Also note a limit neither vendor escapes: **you cannot start somebody mid-automation.**
"Where in the funnel they arrive" has to become *which* automation, plus contact attributes
its internal branches read.

## The PHI boundary

Once "push to a CRM" is a dropdown beside a field list, mapping a quiz's health answers into
a destination that must never receive them is one click, looks exactly like every correct
mapping, and **produces no error**. The driver sees a string, the vendor accepts it, the run
row says success. Nothing downstream can catch it and no log would show it afterwards.

So the check is structural, and it sits in `FieldMap` between the operator's mapping and the
driver.

**Classification travels with the field**, in one vocabulary
(`App\Enums\Privacy\DataClassification`: `general` < `sensitive` < `phi`), rather than each
driver deciding what it will accept. A driver can then be wrong about a vendor's terms without
that being a data-protection failure.

Where a classification comes from:

- **Subject fields** — the `WorkflowRegistry` subject allow-list, which is already the
  boundary everything outbound passes through. `registerSubject()` accepts
  `'age' => ['label' => 'Age', 'class' => DataClassification::Phi]`; a bare string means
  `general`, so existing registrations keep working. **Atlas declares its own**: `age` and
  `gender` are ordinary demographics in a shop and clinical inputs here, because this install
  uses them to gate which treatments may be recommended. The generic layer enforces; the
  domain decides.
- **Quiz answers** — per question. `quiz_questions.data_class` overrides
  `QuizQuestionKind::defaultDataClassification()`, and **null means inherit**, which is the
  state almost every question is in. Always call `QuizQuestion::effectiveDataClass()`; reading
  the raw column treats the commonest case as unclassified.

  **Authored kinds default to `Phi`**, and that is a correction, not caution. They defaulted to
  `Sensitive` first, which reads as protective and is not — only `Phi` engages the gate, so
  `Sensitive` and `General` behave identically at the one moment that matters. On this install
  that left "Anything to flag?" (blood pressure, cholesterol, blood sugar, liver) and "On any
  medications right now?" free to leave for an unattested destination, labelled "· personal" in
  the mapper. The operator downgrades what is genuinely not clinical via the **Sensitivity**
  select on the question; that control has to exist or the protective default is a wall.

  **`HealthGoals` is the one reserved kind that is NOT `Phi`** — the operator's determination,
  2026-08-28. A goal is aspirational ("more energy"), not a condition, a diagnosis or a
  treatment, and it is the answer the whole quiz exists to collect: block it by default and
  every install has to downgrade it by hand before it can segment on anything. `Measurement`,
  `Sex` and `Age` stay `Phi`; so do authored kinds, which is where flags and medications live.
  Note the asymmetry this does not break — an OUTCOME can disclose more than the input, so a
  recommended protocol name is health data even though the goal that produced it was not.

`quiz_answers` is deliberately **not** in the subject allow-list. Registering it would
classify the container and let every answer inside inherit one verdict. `FieldMap` resolves
`quiz_answers.{slug}` per question instead, and reads those values directly rather than
through `WorkflowContext::get()` — which is bounded by the allow-list and would silently blank
every one of them.

**Permission is a setting, not a hardcoded refusal.** An install with an agreement covering
health data is entitled to send it; hardcoding a vendor's name into a refusal would be wrong
for them and would go stale the moment that vendor changed its terms. `FieldMap` compares two
declared facts — the field's classification and the instance's attestation — and has no
opinion of its own about any vendor.

Three outcomes, not two. `block` and `send` alone force a choice between a broken funnel and
an unsafe one, and the unsafe one always wins:

| `on_phi` | Effect |
|---|---|
| `block` (default) | The whole push is refused, naming the field |
| `redact` | The destination learns the field was present; the value never leaves |
| `send` | A deliberate override |

**Unknown sources fail closed.** An unregistered source, a subject with no key, a
`quiz_answers.{slug}` with no question behind it — all classify as `phi`. A source nobody has
classified is precisely the one not to wave through.

**But note exactly what the gate covers: field VALUES classified `Phi`.** `Sensitive` is
informational — it changes a label in the mapper, not whether a value may be sent. Do not read
it as a partial block. Three things are outside the gate entirely and are recorded in
"Known gaps" below.

**The check runs at send time, not only at save time.** An attestation can be withdrawn after
a workflow was authored — that is the point of allowing revocation — and a form-time-only
check would keep shipping data the operator has since withdrawn permission for.

**The identity fields pass through the gate too, as implicit mappings.** A contact needs
something to be keyed by, so `email`, `phone`, `first_name` and `last_name` are sent even when
the operator mapped nothing — but `PushToIntegrationAction::withIdentity()` appends them to the
mapping list rather than reading them off the subject. Reading them directly would be a hole by
construction: harmless only because this install classifies email as personal rather than
health data, and leaking the day somebody reclassified a field the fallback touched. An
explicit mapping for the same source always wins, so an operator's own destination name and
`on_phi` choice are never overridden.

## The attestation

`integration_instances.phi_permitted` is a **cache** of the newest row in
`integration_phi_attestations`. Always write it through `IntegrationInstance::attestPhi()`;
setting the boolean alone produces a permission nobody is recorded as having granted.

It is an **attestation, not a verification**. Nothing here can check whether an agreement
exists — only that a named person said it does, on a date, with a reason. That makes the who
and the when the entire value of the record, which is why a pair of columns is not enough:
they lose the previous answer the second time the flag is toggled, which is exactly when the
question gets asked.

Append-only, enforced in the model exactly as `LeadConsent` is, with the same honest limit:
these are Eloquent model events, so they cover every path through a model instance and nothing
else. A query-builder `update()` or `withoutEvents()` bypasses them. The claim is "no path
through this class", not "impossible".

`permitted = false` is a real record. "Revoked on the 3rd" and "never attested" are different
facts.

The admin exposes this as an **action, not a form toggle** — a field an operator can flip
while editing a display name is a permission nobody is recorded as having granted.

## Consent decides the verb

**`addToGroup()` adding somebody to a Klaviyo list set no consent, and that was a live
defect.** `POST /lists/{id}/relationships/profiles/` puts a profile on a list without
subscribing it. Klaviyo then suppresses marketing to that profile — while the "Added to List"
flow still fires. The funnel looks correct, the run row says success, and the email never
lands. Nothing anywhere reports it.

The fix is **not** "always subscribe". Opting somebody in who did not agree is the worse of
the two errors and the one that reaches a regulator. So the verb comes from **our own consent
audit**:

```
lead_consents (append-only)          →  ConsentResolver  →  ConsentState  →  ContactPayload
  latest row per channel, granted        (live query)        list<channel>     ->consent
```

- **`ConsentResolver` reads `lead_consents`, not the `leads` booleans.** The booleans are a
  current-state cache, written by `RecordConsentAction` at the same moment as the row it
  caches; the audit is what anything outbound consults. A withdrawal is a *new row* with
  `granted = false`, so the resolver takes the latest row per channel and orders by `id` as
  well as `consented_at`, or a grant and a withdrawal captured in the same second resolve
  arbitrarily. (There *was* a path that moved the booleans without an audit row — `LeadForm`'s
  consent toggles. It is closed; see the end of this section.)
- **Resolved at send time, from the database.** A chain carries a trigger-time attribute
  snapshot (see `RunWorkflowChain`) and may run after a withdrawal. This is exactly the field
  where the stale answer is the harmful one — the same rule the PHI attestation follows, and
  for the same reason: a revocation must take effect on the next run with nobody editing a
  workflow.
- **Fails closed.** Non-`Lead` subject, null subject, no rows → nothing granted.

**Consent deliberately does not go through `FieldMap`.** Everything the mapper carries is a
field an operator *chose* to send; consent is an invariant, and a mapping somebody can point
anywhere — or forget — is the wrong shape for the thing deciding whether a person may be
marketed to. It therefore rides on `ContactPayload::$consent`, out of reach of any mapping,
and a mapping whose destination is literally `consent` is nothing but a custom property with a
suggestive name. `ConsentVerbTest` pins that.

**`SyncsContacts::addToGroup()` takes the whole payload, not just the remote id.** A remote id
is enough to add somebody to a list and not enough to subscribe them: consent is per channel,
and a channel needs its identifier — an email grant with no email address subscribes nothing.
A driver may ignore consent where its platform has no such concept (GoHighLevel tags carry
none; its opt-out is a separate DND concept on the contact). What a driver may **not** do is
read consent out of `$contact->attributes`.

Klaviyo's consented path is `POST /api/profile-subscription-bulk-create-jobs/`, which sets
consent and adds to the list in one asynchronous job (202, no body). `subscriptions` is keyed
by channel and an omitted channel is left untouched rather than cleared. **`consented_at` is
deliberately not sent**: Klaviyo accepts it only under `historical_import: true`, which also
bypasses double opt-in and the "Added to List" flows — wrong for a live capture, and our
timestamp evidence lives in `lead_consents` anyway. **This path needs `subscriptions:write` on
the key**, which `test()` does not prove — it only reads `/accounts/`, and a key's scopes are
fixed when it is minted.

The not-consented branch is the operator's, via `when_not_consented` on the action —
`skip` (default) or `add`. `add` exists because not every group is an audience: a tag such as
"quiz-abandoned" is internal bookkeeping. The test is `!== add`, so an unrecognised value in
that JSON column skips rather than adds; `config` is authored by fill scripts as well as by the
form, and a typo must not opt somebody in. A skip is **named in the run result**
(`group_skipped`), because a step that quietly does three-quarters of its job is the failure
this whole slice removes.

**Scope, stated so nobody reads more into it.** Consent decides the verb for the GROUP step of
`sync_contact`, and nothing else. `track_event` and `enroll` do not consult it — enrolment in
particular drops somebody into an automation that can send, with no gate and no
`when_not_consented`. That is not an oversight to leave quiet: an event is the *recommended*
way to reach somebody who has not opted in (it triggers a flow without putting them on a
list), and enrolment is a GoHighLevel concept whose own DND handling sits at the vendor. But if
a second gate is ever wanted, enrolment is where it goes.

**The whole thing rests on `RecordConsentAction` being the only writer**, so the paths that
could break it matter as much as the resolver. The lead form used to carry raw
`email_consent` / `sms_consent` toggles that wrote the cache and no audit row — meaning an
operator could enter somebody's opt-out into a column nothing downstream reads, while the audit
still said granted and the next run subscribed them. Those fields are now `disabled()` **and**
`dehydrated(false)` (disabled alone still submits), and the audit relation manager carries an
append-only "Record a decision" action. A veto — treating a cached `false` as a refusal
regardless of the audit — was tried and rejected: an import writing straight to the audit
leaves the cache at its default, so it would silently discard real consent, and a rule that
only sometimes matches the record is the `Sensitive` mistake again.

## Remote ids

`integration_identities` records what a destination calls one of our records — the id
`upsertContact()` returns, which used to be discarded the moment the run ended.

| | |
|---|---|
| Key | `(integration_instance_id, subject_type, subject_id)`, unique |
| Also indexed | `(integration_instance_id, remote_id)` for reverse lookup |
| Written by | `PushToIntegrationAction`, never a driver |

- **A table, not a column per vendor.** A lead exists in several destinations at once, and a
  column per vendor is a migration every time an operator connects one — in a backend that
  ships to companies whose vendors we have not met. It would also invent a naming convention,
  and this codebase already carries three that compete (`prescribe_rx_*`, `provider_*`,
  `prx_*`). Keyed by the *instance*, so the operator's own row names the destination.
- **Not unique on `remote_id`.** Klaviyo merges profiles on email, so two of our subjects can
  legitimately point at one remote profile; a constraint forbidding that would fail a push for
  being correct. A re-push is an upsert, and the newest id wins — a merge hands back a
  different one.
- **Polymorphic**, because the workflow engine's subjects already are and because progressive
  identify (23c) captures somebody before a lead exists.
- **Cascades on force-delete only.** `IntegrationInstance` soft-deletes, so switching a
  destination off keeps the ids and turning it back on does not recreate every profile.
- **Persisting is best-effort.** The remote push has already succeeded when it runs; failing
  the action there would report a failure that did not happen, and with `tries = 1` nobody
  retries it. A missing row costs one redundant upsert; a false failure costs trust in the run
  log.
- **Index names are explicit.** MySQL caps an identifier at 64 characters and
  `integration_identities` plus three columns overflows it — the same trap that created
  `integration_phi_attestations` with its index silently missing.

## Slugs are guarded

`workflow_actions.config` references an instance by slug inside a JSON column, so no foreign
key can protect it. This project has met the consequence twice — a renamed palette colour
blanks every section using it, a re-slugged disposition orphans its workflows. **When
references are by name, a rename is a removal.** `IntegrationInstance` therefore blocks both
rename and delete while any action points at it, and the operator deactivates instead.

## Credentials

`credentials` is `encrypted:array`; `settings` is plain JSON so an admin table can show
non-secret config without decrypting anything.

**Never render credentials as a key/value editor.** It cannot mask one field, shows every
secret at once, and rewrites the whole blob on save — so a masked placeholder would overwrite
the real secret with the mask. Each driver declares its credential inputs via the
`credentials:` closure on `registerProvider()`, and each becomes a masked `TextInput`, as
`MerchantAccountForm` already does.

## What a handler may put in the run log

**`workflow_action_runs.output` and `.error` are UNENCRYPTED**, and readable by any admin with
run-log access. Two rules follow, and both are tested:

- **Handlers record identifiers, never values.** `remote_id`, a message SID, a status, a count.
  Never a message body, never a mapped field. `PushToIntegrationAction` returns
  `['remote_id' => …, 'fields_sent' => 3]` for exactly this reason, and `TwilioDriver` returns
  the message SID rather than the text it sent.
- **Credentials are scrubbed from error messages.** Several vendor APIs quote the offending key
  back in their error ("the key pk_live_… is not valid"), and that message is persisted verbatim.
  `TalksToVendorApi` remembers every secret read through `credential()` — the single place a
  driver reads one — and redacts them from anything that escapes. Mutation-verified: disabling
  the scrub writes a live key into the error.

## Known gaps

Three things the gate does not cover. All are decisions, not oversights, and all should be
closed before an install builds a funnel that depends on them.

**Operator-authored labels leave unclassified.** The `group` (list or tag name), `event` name
and `automation` id in a `push_to_integration` config are strings an operator types, and
nothing classifies them. `SyncsContacts`' own docblock makes the point: a list named "TRT
interest" discloses health status exactly as a symptom answer does, and so does a protocol or
product name. Today only `user.md` warns about it, in prose.

**`Sensitive` gates nothing.** By design — but it means a field marked personal rather than
clinical reaches every enabled destination. If "warn before sending personal data somewhere
new" is wanted, add it as an explicit second check rather than giving `Sensitive` a partial
meaning only some call sites honour.

**`WebhookAction` is a known bypass around the PHI gate.** It posts `WorkflowContext::toLog()`
to any URL, so an operator can point it straight at a vendor's HTTP API and skip everything
above. Its payload is at least bounded by the subject allow-list — which is why `quiz_answers`
is not on that list — but this should be closed properly, either by classifying its payload or
by treating `webhook` as a destination that is never permitted for health data.

## Shipped drivers

| Key | Vendor | Capabilities | Operations | Credentials | Options |
|---|---|---|---|---|---|
| `local_mail` | This site's own mail stack | `transactional_email` | — | none (uses Communications settings) | from-name override |
| `klaviyo` | Klaviyo | `crm` | contact sync (subscribes on consent), **events** | private API key | list name → id map |
| `gohighlevel` | GoHighLevel | `crm` | contact sync, **enrolment** | API token | location id, source label |
| `twilio` | Twilio | `sms` | — | account SID, auth token | messaging service SID, from number |

**Klaviyo and GoHighLevel are deliberately not symmetrical**, and the table above is the proof
the contract works: Klaviyo implements `TracksEvents` and not `EnrollsInAutomations`,
GoHighLevel the exact reverse. Neither throws from a method it cannot honour, and the operation
picker in the workflow form offers each only what its driver implements. `VendorDriverTest`
asserts the asymmetry directly, so a future edit that "tidies" one to match the other fails.

The two also disagree about grouping, which is why `addToGroup()` takes a semantic **name**:
Klaviyo resolves it through the instance's list map to an opaque id, GoHighLevel passes it
straight through as a tag. One workflow step, two correct meanings.

> **⚠ ALMOST NOTHING HERE HAS BEEN RUN AGAINST A LIVE ACCOUNT.** Verified so far, all Klaviyo:
> the `revision` header, the credential check in `test()`, and the shape of a list id.
> Everything else is built from documented API shapes; the tests fake HTTP and pin the request
> we *intend* to send. Treat a green suite as "this driver does what we meant", never as "this
> driver works". GoHighLevel and Twilio have touched no real account at all — the endpoint
> paths, the version headers (`Version` for GoHighLevel) and the upsert response shapes are the
> first things to check.
>
> **SCOPES ARE THE FIRST THING THAT BREAKS, AND `test()` CANNOT SEE THEM.** Confirmed live on
> this install 2026-08-28: the connection test passed and `POST /profile-import/` answered
> `403 — Your API key is missing required scopes: profiles:write`. The key was read-only, so no
> lead had ever reached Klaviyo at all, and nothing said so. `test()` reads `/accounts/`, which
> needs only `accounts:read`; there is no endpoint that reports a key's scopes; and scopes are
> fixed when a key is minted, so the fix is always a new key rather than an edit.
>
> The driver needs `accounts:read`, `profiles:write`, `lists:write`, `subscriptions:write`,
> `events:write`. They are named in the credential field's helper text for exactly this reason.
> Everything past the profile upsert — the subscribe envelope included — is therefore **still
> unverified against a live account**; the 403 arrived before any of it was reached.

Notes worth carrying, each of which shaped the code:

- **Klaviyo's `external_id` does not participate in profile merging.** Keying on our lead id
  creates duplicates rather than preventing them, so identity is email/phone and `external_id`
  is a back-reference only.
- **Klaviyo pins behaviour to a dated `revision` header**, which is effectively the API version.
  Changing it can change response shapes.
- **A Klaviyo private key's scopes are fixed at creation.** Widening them means a new key, which
  the credential field's helper text says so an operator does not go looking for an edit screen.
- **Every GoHighLevel call is scoped to a location** (sub-account). That id is configuration,
  not a secret — it names the account, it does not open it — so it sits in `settings` unmasked.
- **Neither vendor can start somebody mid-automation.** "Where in the funnel they arrive" has to
  become *which* automation plus contact attributes its branches read.
- **Twilio's REST API is form-encoded**, not JSON, and answers a JSON body with an unexplained
  400. `TwilioDriver` uses `asForm()` and a test pins the content type.
- **Twilio will sign a BAA**, making SMS one of the few channels where health content can be
  legitimate. That is still the operator's attestation; no driver enforces it.
- **Klaviyo's acceptable-use policy bars health data and they sign no BAA.** That is a fact
  about their terms, not a rule any class here enforces — `FieldMap` and the operator's
  attestation decide. Do not add a hardcoded refusal: it would be wrong for an install whose
  contract differs, and stale the moment their terms change.

## Messages whose links are credentials

`EmailMessage::$trackLinks` (default `true`) is `false` for a message whose links carry a
secret — today the patient claim link. Provider click tracking rewrites every link through the
vendor's redirector, which hands the token to a third host; on Mailgun that redirector is plain
HTTP on the install's tracking CNAME by default. Each driver maps the flag to its vendor's
per-message opt-out, so an operator switching tracking on in a dashboard cannot leak one.

| Driver / transport | Mapping |
|---|---|
| `local_mail` on Mailgun | `o:tracking-clicks: no`, `o:tracking-opens: no` (the Symfony Mailgun API transport forwards `o:` headers as sending options) |
| `local_mail` on SES, Postmark, SMTP | **not mapped** — an `o:` name is not a valid header there. Keep link tracking off at the vendor. |

A vendor driver added later that offers `transactional_email` must honour the flag or say in
its docblock that its vendor cannot track links.

**System sends reuse the routing, not the workflow.** `RequestClaimLinkAction` calls
`CapabilityRouting::resolve(..., TransactionalEmail, null, ...)` — no named instance — so the
"exactly one" rule applies: two active instances offering `transactional_email` make the claim
link unavailable (503) until one is switched off. A per-purpose default instance is the obvious
next step if an install needs two.

## Adding a provider

1. Implement `IntegrationDriver` plus the capability interfaces you can genuinely honour.
   Do not implement one you would have to throw from.
2. Register it in `IntegrationServiceProvider` (or your own provider) with a key, a label, and
   `credentials:` / `settings:` closures returning Filament components.
3. That is all. The admin form, the capability checkboxes, the action palette and the
   operation picker all read the registry.

`LocalMailDriver` is the reference: it registers this site's own mail stack as a provider so
`send_email` is uniformly capability-routed and works on a fresh install with no vendor
account anywhere. It deliberately does **not** offer `marketing_email` — Laravel's mailer
cannot honour an unsubscribe.

## Files

| | |
|---|---|
| Catalogue + capability derivation | `app/Integrations/IntegrationRegistry.php` |
| Capability ↔ interface map | `app/Enums/Integrations/IntegrationCapability.php` |
| Driver contracts | `app/Integrations/Contracts/*.php` |
| PHI gate | `app/Integrations/FieldMap.php` |
| Classification vocabulary | `app/Enums/Privacy/DataClassification.php` |
| Consent → verb | `app/Integrations/ConsentResolver.php`, `app/Integrations/Messages/ConsentState.php` |
| Models | `app/Models/Integrations/*.php` |
| Actions | `app/Workflows/Actions/{PushToIntegration,SendEmail,SendSms,CapabilityRouting}*.php` |
| Admin | `app/Filament/Resources/Integrations/`, `app/Filament/Support/IntegrationActionForms.php` |
| Where vendors are declared | `app/Providers/IntegrationServiceProvider.php` |
