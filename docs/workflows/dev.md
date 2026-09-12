# Workflows — developer reference

"When this happens and these things are true, do that." An operator-configurable
automation engine. Nothing in `app/Workflows` knows what a lead is.

## Why a registry and not enums

This backend is deployed by companies we will never meet, running funnels we cannot
anticipate. An enum of trigger models or action types would mean every one of them forks
the codebase. `App\Workflows\WorkflowRegistry` is a singleton they add to from their own
service provider; the engine, the admin form and the condition builder all follow.

**It is also the security boundary, and that is not a secondary benefit.** Rows in
`workflows` and `workflow_actions` are operator-editable and name what to run. Held as
class names and instantiated, that is arbitrary class instantiation in a product hundreds
of companies deploy. They hold **registry keys**, which resolve only to something the
install deliberately registered.

Three registration surfaces:

```php
$registry->registerSubject('lead', Lead::class, 'Lead', ['status' => 'Disposition', …]);
$registry->registerEvent('lead.created', LeadCreated::class, 'Lead captured', 'lead');
$registry->registerAction('webhook', WebhookAction::class, 'Send a webhook');
$registry->registerJob('sync_crm', SyncCrmJob::class, 'Sync to CRM');   // dispatch allow-list
```

The subject `fields` array is an **allow-list, not documentation**. It bounds what a
condition may read and what an update-field action may write. An unregistered subject
allows nothing — reads fail closed, so a typo'd key never becomes "anything goes".

Atlas's own registrations live in `App\Providers\WorkflowServiceProvider` and are the only
place a domain concept meets the engine.

## Data model

| Table | Holds |
|---|---|
| `workflows` | trigger_type, trigger_target (registry key), `conditions` json, is_active, priority, stop_on_first_match |
| `workflow_actions` | action_type (registry key) + `config` json, sort_order, is_active, halt_on_failure |
| `workflow_runs` | one row per evaluation **including skips**, with `skip_reason` and a `context` snapshot |
| `workflow_action_runs` | one row per step attempted, with `output` or `error` |

`trigger_type` is one of `model_created` / `model_updated` / `model_deleted` /
`event_fired` / `manual`. A string, not an enum column — an install may register a kind
this codebase has never heard of.

## Conditions reuse VisibleWhen — never fork it

`conditions` is the same `[{field, operator, value}, …]` shape the CMS and the quiz already
use, evaluated by `App\Cms\Support\VisibleWhen`. One condition vocabulary for the whole
product, one evaluator to keep correct.

**Change semantics without forking it.** A workflow's most useful conditions are about
change — "became `quiz_complete`", "moved *from* `new`" — which a plain attribute read
cannot express. `WorkflowContext::get()` exposes them as pseudo-fields in the same flat
namespace:

| Field | Reads |
|---|---|
| `status` | the value now |
| `_original.status` | the value before this write |
| `_changed.status` | `'1'` when this write altered it, `''` otherwise |
| `_payload.foo` | a scalar property of the triggering event |

So "moved to quiz_complete from new" is two ordinary conditions and needs no new operators.
`_changed.*` returns a string because VisibleWhen compares via string casts.

Conditions are **ANDed**. There is no OR grouping: two workflows plus `stop_on_first_match`
expresses the same thing, and a boolean tree is a much larger UI than it earns.

## Loop protection — read this before changing the dispatcher

The most useful action is "update a field" and the most useful trigger is "a field
changed". Together they are a cycle, and it is the **first** thing an operator builds.
`WorkflowDispatcher` holds two guards, because they fail differently:

- **Re-entry** — a workflow runs at most once per subject per chain. Catches the direct
  cycle, which is otherwise infinite and writes rows every turn.
- **Depth** (`MAX_DEPTH = 5`) — catches the indirect cycle, A→B→A, which re-entry alone
  would not, since neither workflow repeats for the same subject until the second lap.

The chain is per-process and clears when it drains. A queued listener gets a fresh chain,
which is correct: reacting to a change made by an earlier, finished chain is new causation,
not a loop.

**This is why the chain is queued at its root and nowhere else** — see "Queued execution"
below. Both guards are per-process state, and the re-entry claim has to be visible to the
write that an action makes from inside `run()`. Put each hop on the queue and every hop gets
its own copy of the bookkeeping: two actions raising triggers fork the chain into jobs whose
claim sets diverge, release-on-skip in one branch is invisible to the other, and the guard
has to become a distributed lock to buy nothing. This is not theoretical — deleting the
depth check in `queue()` and re-running the suite does not fail a test, it **hangs**, because
the direct cycle becomes infinite again.

**Re-entry is mutation-tested**; removing it makes the run count 2 instead of 1. **The
depth guard is not.** Re-entry bounds each *workflow* to one acting run, not chain *depth* —
a chain of N distinct workflows on one subject still nests, and enough of them will reach
the cap. It is the backstop for that and for cross-subject actions. Do not remove it on the
grounds that no test covers it.

**The re-entry guard claims the fingerprint BEFORE running and releases it if the run
skipped.** Both halves are load-bearing and each was proven by breaking it: claim after the
run and an action's own write re-enters before the mark lands; hold the claim through a skip
and an ordinary two-stage funnel breaks — workflow A makes workflow B's condition true, and
B, having already been evaluated-and-skipped earlier in the chain, is suppressed and never
fires. That second failure is worse than the loop, because nothing says why.

## Execution

`WorkflowRunner` records **every** evaluation. A skipped run costs a row and answers the
only question operators ask about a workflow that looks broken — so on failure it
re-evaluates one condition at a time to produce a human `skip_reason`
(`status equals "quiz_complete" — actual: "new"`).

Actions run in order. **A failure does not abort by default**: a CRM push that fails must
not prevent the disposition update behind it. `halt_on_failure` is per action.

`WorkflowSubjectObserver` sets `$afterCommit` for the same reason `LeadObserver` does —
several actions move models inside a transaction, and you cannot un-send a webhook.
An update that changed nothing is not a trigger (`getChanges() === []`).

### `_original.*` and `$afterCommit` — do not "simplify" this back

**A commit callback runs after `syncOriginal()`, so `getOriginal()` inside an
`$afterCommit` handler returns the NEW value.** Read it there and `_original.status` equals
the current status, `$from === $to`, and a transition condition can never match. It shipped
that way once: every transactional status write — including the prescribe-rx checkout
handoff, the highest-value transition in the funnel — dispatched **nothing at all**, while
direct writes worked perfectly and the whole suite passed.

`App\Support\ModelChangeSnapshot` captures the previous values at `updating`, before the
sync, into a `WeakMap` keyed by the model. Three properties of it are load-bearing, each
found by breaking it:

- **A WeakMap, not a dynamic property or an `spl_object_id` array.** A dynamic property goes
  through Eloquent's `__set` and becomes a fake attribute that tries to save to a missing
  column; an id-keyed array leaks on rollback and collides after GC reuses ids.
- **Reads are non-destructive.** `Lead` carries two observers; a consuming read starves
  whichever runs second and silently hands it the post-sync values.
- **Captures are idempotent per write, and chain-aware.** Idempotent because those same two
  observers both capture for one write — without the guard the second treats the first as a
  previous write. Chain-aware because in a chain the sync may not have run yet: with a real
  surrounding transaction it has by commit time, but on the non-transactional path an
  "afterCommit" handler runs inline inside `performUpdate`, before `finishSave()` syncs. The
  merge (`original` + `applied` from the previous capture) is correct either way, which is
  why it is unconditional.

A known limit, named so it is not rediscovered: a `saveQuietly()` / `withoutEvents()` /
query-builder write landing between two observed writes on the same instance is invisible to
this, so the merged `original` describes the state before it. Nothing does that to a
registered subject today.

`getChanges()` is unaffected by any of this and stays authoritative for *what* moved; only
the previous *values* needed rescuing.

Pinned by `test_original_survives_a_write_inside_a_transaction`,
`test_original_is_per_write_and_not_inherited_by_a_chained_one`, and (for the event)
`test_the_event_fires_for_a_write_inside_a_transaction`. All three fail if the observers go
back to `getOriginal()`.

## Registered subjects and events

| Subject | Model | Registered in |
|---|---|---|
| `lead` | `Lead` | `registerAtlasSubjects()` — Atlas's classifications |
| `patient` | `Patient` | `registerPortalSubjectsAndEvents()` — part of the shipped portal, so an install replacing Atlas's registrations keeps it |

| Event key | Subject | Fired |
|---|---|---|
| `lead.created` | lead | any lead capture |
| `lead.disposition_changed` | lead | status move (`changedField: status`) |
| `quiz.completed` | lead | quiz lead created |
| `patient.claim_link_requested` | patient | a claim link was actually emailed |
| `patient.record_claimed` | patient | a claim committed and the record is linked |
| `patient.email_verified` | patient | first verification only |

**The `patient` allow-list omits `prx_patient_chart_id`, `password` and every token column**
on purpose: this list is what a field mapper may send to a third party, and a chart id is
identifying even though it is not clinical.

🔴 **The claim email itself is NOT a workflow action and must not become one.** It carries a
single-use credential; workflow context is serialised into a job and written to
`workflow_runs.context`. The system sends it (`RequestClaimLinkAction`) through the same
capability routing `send_email` uses, and the `patient.*` events carry the patient and nothing
else — hang follow-ups there. See `docs/portal/dev.md`.

Registering `patient` also attaches `WorkflowSubjectObserver` to it, so `model_created` /
`model_updated` triggers exist for patients too.

**A queued chain carries no `$hidden` column.** `RunWorkflowChain::forContext()` strips the
subject's `$hidden` keys from both `subjectAttributes` and `original` (values; the `changed`
list may still NAME a hidden column, and `_changed.*` is bounded by the allow-list) before the job is
serialised — the payload sits in Redis and, on a crash, in `failed_jobs` where Horizon shows it,
and a Patient row carries a password hash. Nothing downstream needs them.
`WorkflowQueueTest::test_hidden_columns_never_enter_the_job_payload` asserts on the serialised
job. A new subject's secrets belong in `$hidden` for this to cover them.

## Shipped actions

| Type | Config | Notes |
|---|---|---|
| `update_field` | `{field, value}` | Bounded by the subject allow-list. Throws on an unregistered field rather than silently ignoring it. |
| `webhook` | `{url, method?, headers?, timeout?}` | Non-2xx is a **failure**. Payload is `context->toLog()`, so it carries only registered fields. |
| `dispatch_job` | `{job}` | Registry key only. The most dangerous action, hence its own allow-list — **empty until an install calls `registerJob()`, and the action is withheld from the palette while it is.** |

**Nothing is registered on the Atlas install**, so "Run a background job" does not appear in
its palette at all. That is the allow-list working rather than a gap: a job is arbitrary code
dispatched from an operator-editable row, so the list starts empty and stays empty until
somebody deliberately puts a class on it. It used to be offered regardless, which gave an
operator a step whose only control was an empty dropdown — the failure `actionOptions()`
already forbids, slipping through because that gate only understood integration capabilities.
`registerAction(available: …)` is the general form of it.

Note the two jobs this install *has* — `RevalidateFrontendJob` and
`HandlePrescribeRxWebhookEvent` — are not candidates: `DispatchJobAction` constructs
`new $job($context->subject)` and neither takes a subject. Registering one means giving it a
constructor that does.

**Deliberately absent: any vendor.** Klaviyo/GHL/Twilio arrive as configured integration
instances behind one generic `push_to_integration` action, so the shipped set never names a
third party. See the next-session notes.

## Queued execution

A trigger no longer runs its workflows on the thread that raised it. Everything outside the
engine calls `WorkflowDispatcher::queue()`, which pushes one `RunWorkflowChain` job; the job
opens the chain and every trigger raised *inside* it runs inline. The public
`POST /api/v1/leads` therefore returns without waiting for anybody's CRM.

| | |
|---|---|
| Queue name | `workflows` |
| Connection | `workflows` in `config/queue.php` (`retry_after` 300s) |
| Supervised by | `supervisor-workflows` in `config/horizon.php` |
| Retries | **none** (`$tries = 1`) |
| Timeout | 180s, vs 60s on `default` |

**The timeout and `retry_after` are one setting in two files.** `retry_after` is when Redis
decides a reserved job was abandoned; the worker timeout is how long a chain may take. Cross
them and a chain that outlives its reservation is handed to a second worker, which sees the
attempt count exceeded and marks it **failed without running it**, while the first worker
finishes the same chain successfully — a failure in the run log that never happened. Raise
`tries` on top of that and the redelivery *executes*, sending every webhook in the chain
twice. That pairing is why workflows have their own connection rather than sharing `redis`
(90s), and `WorkflowQueueConfigurationTest` pins it so neither side can be retuned alone.

Four things worth knowing before changing any of it:

- **The queue name is not decoration.** Nothing else watches `workflows`. Delete that
  supervisor and the work does not fall back to `default` — every trigger enqueues a job no
  worker collects, and the symptom is an admin showing no runs and no errors. Workflow chains
  are on their own queue so that a slow CRM push cannot sit in front of cache revalidation or
  an inbound prescribe-rx event.
- **It does not retry, deliberately.** Per-action failures are already caught and recorded as
  `workflow_action_runs` rows, so a failure that reaches the job means the chain died
  part-way — and a retry re-runs the actions that already completed. Webhooks do not un-send.
  At-least-once is the wrong default for an engine whose actions are side effects on other
  people's systems. A `failed()` hook logs what the run log could not.
- **The subject travels as an attribute snapshot, not a model reference.** `SerializesModels`
  would re-fetch by key, which throws for `model_deleted` — the one trigger whose whole point
  is that the row is gone — and, when the row has moved since, would evaluate conditions
  against new state while `_original.*` still described the old write, stitching a context out
  of two moments. So the attributes are carried as they were at the trigger and rehydrated
  with `newFromBuilder()`. An `update_field` still emits an `UPDATE` of only the field it
  sets, so a stale snapshot cannot clobber a column something else moved. If the subject was
  deleted in the gap, that write matches zero rows and says nothing — a correct no-op, and a
  silent one. An action needing the freshest state should re-read inside its own handler.
- **A run row appears when the chain executes, not when it is queued.** There is no "pending"
  row: at enqueue time the matching workflow set is not yet known, and `workflow_runs`
  requires a `workflow_id`. Work in flight is visible in Horizon, not in the run log.

**Deploying:** Horizon workers hold the code they booted with. After any deploy touching the
engine, `sudo supervisorctl restart atlas-protocol-admin-horizon` (or `php artisan
horizon:terminate`), or queued chains run the previous release.

**Two behavioural changes to know about.**

*An API response is now serialized before the workflows fire*, so a lead-create response
reports the disposition as it was written, not as a workflow later moved it. The Atlas
frontend reads only `lead.uuid` (`QuizWizard`) and `handoff_url` (`CheckoutClient`) off that
response, neither of which a workflow can write — but a frontend reading `status` would see
the pre-workflow value.

*Chains raised by one request now run concurrently.* A single `POST /api/v1/leads` carrying a
quiz raises three root triggers — `model_created`, `lead.created`, `quiz.completed` — which
used to run in deterministic order in one process and are now three jobs across up to five
workers. Each evaluates its own trigger-time snapshot correctly, so nothing races on data;
what is no longer guaranteed is ORDER between them. If one workflow sets a field another
conditions on, do not express that as two workflows on two different triggers of the same
request — chain them off the field change instead, which is what the `_changed.*` conditions
are for and is deterministic because it happens inside one chain.

## Known gaps
- Action config is a generic key/value editor. A per-action-type schema is the obvious next
  improvement; not required for correctness.
- No `scheduled` or `manual` trigger runner yet — the columns accept them, nothing fires them.
- No shared Filament condition-builder component; `WorkflowForm`, `FlexibleSectionTypeForm`
  and `StepsRelationManager` each build their own repeater over the same shape. Worth
  extracting.
