# Dispatch preparation and transaction association evidence

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
A future execution adapter needs its own qualified dispatch ownership, uncertain-call
handling and durable outcome contract; no existing gateway mutation is wired here.

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

Production accounts, unsupported/unknown payment rails, and account/currency mismatches
remain unqualified by this policy. Current generic DTO flags remain
`currency_authority=current_merchant_configuration` and
`transaction_currency_verified=false`; no blanket historical-currency flag is enabled.
The [investigation](receiver-contract-investigation-2026-09-15.md) describes the narrower
North American account-policy evidence and broader production/legacy/processor questions.
An operator-provided currency or current order total cannot manufacture that authority.

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

`RecordAuthorizeNetAssociationAction` currently supports sale and authorization only.
Capture, void and refund refuse before HTTP; deriving refund parent association
evidence remains future work. Sale and authorization references can be checked under
the existing reporting contract.
Capture and void act on prior transactions, so current transaction state does not establish
which local operation or actor performed them. Refund original-operation evidence must
be derived from qualified parent association/lineage, not a caller-supplied original ID.
The [official transaction contract](https://developer.authorize.net/api/reference/features/payment-transactions.html)
distinguishes a refund's new transaction from capture/void handling. Unsupported lineage
refuses; no original operation is inferred from amount, email, order or matching timestamps.

## Remaining execution and release prerequisites

No preparation or association changes operation uncertainty, paid order state, revenue,
refund availability, canonical events, marketing attribution or vault readiness. Future
financial reconciliation needs supported operation lineage and dispatch provenance,
current conflict policy, production currency authority, settlement/reversal accounting
and concurrency-qualified balance rules. Captured and settled states remain distinct.

Public receiver activation still needs a current provider signature fixture, explicitly
verified account/webhook mapping, bounded HTTP ingress, durable acknowledgement and
approved worker controls. Deployment, served migrations, live provider calls, payment
execution and destination activation require separate authorization. These migrations
must leave new tables empty; preserve retained evidence, HMAC/encryption keys and parent
records in any separately approved rollback or maintenance procedure.

## Qualification

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
