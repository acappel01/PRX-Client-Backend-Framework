# Dispatch provenance and operation lineage

September 15, 2026 continuation of the [inactive receiver](inactive-receiver.md).
These are internal development APIs. No gateway mutation, public endpoint, worker,
automatic inbox consumption, checkout integration or monetary projection is activated.
Callers must establish authorization before invoking local actions. Synthetic tests and
faked reporting responses do not qualify live accounts or real payment execution.

## Durable preparation

`PreparePaymentDispatchAction::execute(PaymentDispatchPreparationData)` takes a durable
UUID, existing operation-reference ID and executor key. It derives the rest from current
locked records: the full operation and intent identities, exact merchant/binding and
canonical account/environment, reference, amount/currency/purpose, ownership/configuration
fingerprints and local original-operation lineage. These allowlisted snapshots are
encrypted and hidden from generic serialization. No raw gateway request, instrument,
card/bank data, nonce or arbitrary metadata is stored.

A new preparation requires a prepared operation, matching executor, current frozen scope
and no uncertain operation on that intent. One preparation is allowed per reference and
operation. UUID/reference/executor conflicts refuse; exact replay returns the immutable
historical preparation, including after uncertainty or configuration drift. Replay does
not check execution readiness and must never authorize another call. Ordinary changes
and deletes are refused; restrictive foreign keys preserve parent history.

The action rejects caller-held transactions and returns after its own commit. The server
assigns `prepared_at`; model serialization and database storage preserve microseconds.
The state remains `prepared_only`: neither the operation state nor any payment balance
changes. A preparation is proof of a local committed record, not that a network call was
sent, accepted or completed. It is not an executor lease or an order-wide interlock.
The separate dispatch boundary below owns invocation attempts; no existing gateway
mutation is wired into it.

## Reporting evidence and currency policy

The existing reporting DTO adds optional `submitted_at` from direct transaction
`submitTimeUTC`, and an allowlisted `payment_rail` classification. Strict UTC `Z` dates
with zero to six fractional digits are accepted; invalid dates, unsupported precision,
attributes, duplicate or foreign-namespace lookalikes refuse instead of being normalized
or rounded. Missing time remains null for generic reads. Classification accepts one
unambiguous payment choice; only its category crosses the XML boundary. Card numbers,
bank details, profile identifiers and tokens remain discarded.

The [official reporting reference](https://developer.authorize.net/api/reference/index.html#transaction-reporting-get-transaction-details)
and installed Authorize.Net schema distinguish transaction submission time from current
read time. Association requires submission strictly later than persisted preparation and no
later than the read. Equal timestamps do not prove precedence. This conservative comparison uses no clock-skew allowance: unknown
or inconsistent timestamps cannot prove preparation ordering. It still does not prove
that our executor was the actor who submitted the request.

`AuthorizeNetCurrencyAuthority::assess(binding, read)` is a pure policy assessment of an
already authenticated same-account read. It does not authenticate caller-constructed
DTOs or perform HTTP itself. Sandbox credit-card reads in the supported singleton
currency can receive `authorize_net_fixed_sandbox_currency_v1`, with
`currency_qualified=true`, `transaction_currency_observed=false`. This is explicitly an
inference from the [provider sandbox policy](https://developer.authorize.net/support/common-setup-questions.html)
requiring another sandbox account to change currency, not a returned transaction-currency
field or evidence that real funds moved.

Production qualification is now a narrow fixed-account policy inference under
`authorize_net_fixed_north_american_account_currency_v1`. The authenticated merchant
read must return exactly one processor with an exact documented North American name,
a valid positive processor ID and a supported USD/CAD combination. Names are matched
against the [official processor/currency policy](https://support.authorize.net/knowledgebase/article/000001210/en-us);
numeric IDs are retained as evidence, not mapped through guessed identifiers. Only
credit-card reporting qualifies. Missing, unknown or multiple processors, alternative
rails, unsupported currencies and account mismatches remain unqualified. API names
that differ from the documented names need separate evidence before extending the map.
No production merchant was contacted or qualified during development.

Merchant processor metadata is bounded to 16 entries and strictly parsed for duplicate,
foreign-namespace, attributed, malformed and out-of-range identity fields. Only names
and IDs cross the boundary; merchant contact and instrument data stay discarded.
The policy's qualified result, including processor identity, is encrypted with association
evidence. Later unqualified evidence blocks the historical association assessment.
Generic transaction DTO flags remain `currency_authority=current_merchant_configuration`
and `transaction_currency_verified=false`. This policy never claims an observed
transaction-currency field, settlement currency conversion or real financial effects.
Legacy/international and other processor/rail combinations remain outside qualification.
An operator-provided currency or current order total cannot manufacture this authority.

## Association and conflict boundary

Association records are authenticated reporting evidence against a durable preparation,
not verified execution or confirmed monetary effects. Exact canonical account/environment,
merchant reference, purpose/amount and preparation ordering are independent checks.
A current local scope check is required before and after remote reads, with no HTTP
inside database transactions. Historical configuration drift cannot silently rebind an
operation. Currency-unqualified evidence remains distinguishable from qualified policy
inference and must not become usable financial state.

`RecordAuthorizeNetAssociationAction::execute(preparationId, transactionId)` obtains
its own authenticated reporting response; it accepts no caller-authored fact DTO. It
stores immutable encrypted evidence and returns a historical graph assessment. Failed
reads, mismatched references/amounts/scope, unsupported lineage and invalid timing refuse
without storing an association. Valid conflicting candidates are retained; rejected
inputs are not falsely labeled authenticated transaction evidence.

`ResolvePaymentTransactionAssociation::execute(preparationId)` recomputes the connected
operation/transaction component under one canonical-account/environment mutex, including
evidence reached through independently verified duplicate local merchant rows. States
are `missing`, `currency_unqualified`, `conflict_quarantined`, `associated_only`, or
`evidence_limit_quarantined` when the 256-evidence bound is exceeded. All returned
`dispatch_verified`, `operation_verified` and `financial_effects_verified` flags remain
false. The resolver assesses stored historical evidence and current conflicts; it does
not revalidate present order/merchant readiness. Its result cannot authorize execution.

Contradictory candidates must remain visible: multiple transaction IDs for one operation,
or one account-scoped transaction associated with multiple operations, quarantine the
connected candidates. Receiving evidence first cannot make it a permanent winner.
Notification deduplication, transaction association and financial effect accounting are
separate identities. The inactive inbox is not automatically consumed by this work.

`RecordAuthorizeNetAssociationAction` continues to support sale and authorization
entity associations. Linked effects use the separate action below so a legitimate
capture/void sharing the original transaction does not create a false root conflict.
A refund entity claimed as a root is quarantined symmetrically on both sides.

## Owned transport invocation

`DispatchPreparedPaymentAction` takes preparation UUID and executor key. It is disabled
unless `payments.dispatch_enabled` is explicitly true and an internal caller supplies a
trusted `PaymentDispatchTransport`. There is no shipped transport implementation,
default binding, route, job or checkout integration. Setting a flag alone cannot send a
payment. The synthetic transport proves boundary behavior, not actual provider delivery.

The action locks the canonical account and intent, checks current frozen scope, derives
an allowlisted request, and commits one exclusive attempt per preparation **before**
invoking transport outside all transactions. Exact replay returns the stored attempt
without invoking transport again. Caller transactions, executor/transport mismatch,
uncertainty, competing root attempts and unrelated sibling attempts refuse. Linked
purposes require matching approved parent/ancestor attempts and conflict-checked parent
evidence. Retained parent status and amount must permit the requested purpose: an
uncaptured authorization for capture, settled capture/sale for refund, and a supported
pre-settlement state for void. This is a check against stored evidence, not a fresh remote
read or a guarantee that the provider still permits the action.
This interlock is intent-scoped; it is not a complete order-wide collection policy.

Attempt states distinguish `claimed`, `response_observed` and `outcome_unknown`.
A crash before or during transport can leave a durable claim with no outcome; a timeout,
invalid response or failed receipt persistence cannot authorize replay. Request scope
and minimal response facts are encrypted; no instruments, request bodies or exception
payloads are retained. A recorded invocation or response does not prove bytes reached
the provider, provider acceptance, settlement or money movement. A future concrete
adapter must verify the frozen credential fingerprint before remote I/O and qualify its
request/response contract and ambiguous-outcome handling before activation.

## Capture, refund and void evidence

`RecordAuthorizeNetOperationLineageAction(preparationId, dispatchAttemptId)` derives the
parent entity from stored evidence and obtains authenticated reporting itself. It accepts
no caller-supplied original transaction ID or reporting facts. Parent reporting evidence
alone is insufficient: a matching owned approved response, exact request scope and
consistent transport timing are required. It rechecks local scope and parent conflicts
before and after remote reads, with no HTTP inside database transactions.

Capture and void keep the original entity ID and submission identity. Capture reads use
an explicit settlement amount basis because the reporting type can remain the original
authorization type after capture; void requires the reported voided status. Refund requires
a distinct transaction ID, exact parent `refTransId`, its own merchant reference and
submission inside the owned transport interval, plus a current settled parent read.
The [provider transaction guide](https://developer.authorize.net/api/reference/features/payment-transactions.html)
and [historical reporting guide](https://www.authorize.net/content/dam/anet-redesign/documents/ReportingGuide_SOAP.pdf)
support these distinctions. The reporting PDF is deprecated (October 2017); its
original-type examples are historical evidence, not current live-provider qualification.
A concrete adapter must qualify current response fixtures before activation. Current entity status alone never establishes the local actor. Shared-entity reads do
not assume that reporting echoes the latest child reference or permanently retains the
original reference; the child receipt must echo its own exact reference, while reporting
must match the owned original entity/type/amount/submission identity.

Encrypted immutable effect evidence stays separate from root transaction associations.
`ResolvePaymentOperationLineage` recomputes parent status and bounded conflicts rather
than making the first evidence permanent. Competing effect actors, refund/root entity
collisions, ambiguous same-parent refund claims and excessive refund claims quarantine
related evidence. Graph limits also quarantine. Associated refund amounts are evidence
bounds, not an available refund balance. `effect_correlated_only` means the owned
response and reporting correlate; financial and operation verification remain false.
No uncertainty, order payment state, accounting balance or canonical event is changed.

## Remaining execution and release prerequisites

No preparation or association changes operation uncertainty, paid order state, revenue,
refund availability, canonical events, marketing attribution or vault readiness. Future
financial reconciliation still needs a qualified concrete transport, settlement/reversal
accounting, current conflict policy and concurrency-qualified balance rules. Production
currency support beyond the narrow policy above needs further provider evidence. Captured and settled states remain distinct.

Public receiver activation still needs a current provider signature fixture, explicitly
verified account/webhook mapping, bounded HTTP ingress, durable acknowledgement and
approved worker controls. Deployment, served migrations, live provider calls, payment
execution and destination activation require separate authorization. These migrations
must leave new tables empty; preserve retained evidence, HMAC/encryption keys and parent
records in any separately approved rollback or maintenance procedure.

## Dispatch and lineage qualification

Independent Astra review passed with no remaining findings. Independent SQLite checks
passed **58 tests / 704 assertions**: reporting **24 / 245**, dispatch and root association
**22 / 208**, lineage **11 / 189**, populated-schema upgrade **1 / 62**. Review findings
were fixed for required parent dispatch ownership, symmetric refund/root conflicts,
ambiguous sibling refund claims, excessive refund totals, submission/response timing
and purpose-specific retained parent eligibility. The upgrade preserves legacy rows and
leaves both new tables empty. No financial projections or automatic consumers were added.

Synthetic dispatch tests pass **11 / 101** in SQLite and **3 / 40** in disposable
MySQL. **10/10** real-process scenarios cover same-preparation races, competing root
preparations on one intent, timeout, process exit after invocation before receipt, and
process exit after claim commit before invocation. Durable attempt and invocation-log
checks establish one invocation in the former cases, zero in the pre-call crash case,
and no new invocation on replay. No real gateway transport was used.

Temporary boundary harnesses are under `/tmp/customer-mysql-20260914-uUkDgL/`:
`phpunit-boundary.xml`, `boundary-race.php`, `run-boundary-races.py`. They use only
`boundary_test` on the private Unix socket with TCP disabled. They are session evidence,
not production tools.


Lineage MySQL qualification passed **2 / 46** plus **15/15 real-process scenarios**:
owned-receipt replay, parent conflict during reporting, symmetric root/refund collision,
credential drift during reporting, and a stale repeatable-read snapshot followed by a
new parent conflict (three each). Harnesses are `lineage-race.php` and
`run-lineage-races.py` under `/tmp/customer-mysql-20260914-uUkDgL/`, scoped exclusively
to `lineage_test`. The socket-only server was stopped after both tracks completed.
Final integrated SQLite regression passed **1,683 tests / 8,106 assertions**. All data,
reporting and transport responses were synthetic; no live accounts were contacted.

## Prior preparation/association qualification

Independent Astra review passed with no remaining findings. Independent focused SQLite
verification passed **40 tests / 397 assertions**: reporting **22 / 216**, preparation
**8 / 85**, association **10 / 96**. Checks include persisted microsecond preparation,
strict submission ordering, duplicate-reference/transaction conflict propagation,
current scope/state drift during reads, storage failure, immutable evidence and bounded
graph overflow. Currency policy and unsupported lineage refuse outside their scope.
The populated-schema upgrade rehearsal passed **1 / 58**, preserving legacy records
and leaving all three new tables empty. The integrated final SQLite regression passed **1,658 tests / 7,772 assertions**.
MySQL concurrency evidence is recorded in the coordinated release checklist.

Disposable MySQL preparation tests passed **3 / 45**, including precise timestamp
storage, identity constraints and uncertainty. **12/12** process scenarios covered exact
replay, conflicting UUID/reference/executor, and current credential/uncertain-state drift.
Association MySQL tests passed **2 / 13**; **15/15** process scenarios covered replay,
one operation/multiple transactions, a shared transaction across duplicate local merchant
operations, separate canonical accounts, and credential drift during reporting. HTTP fakes
asserted no caller transaction. The server used only `dispatch_test` and `association_test`
on the private socket, with TCP disabled, and was stopped after qualification.

Temporary qualification harnesses are `/tmp/customer-mysql-20260914-uUkDgL/dispatch-race.php`,
`run-dispatch-races.py`, `association-race.php` and `run-association-races.py`. They are
session evidence, not production tools. Long automatically generated association foreign
key names were replaced with explicit short names before final MySQL qualification;
a superseded dispatch test process was stopped and its three selected cases rerun.
