# Gateway transport and financial reconciliation

September 15, 2026 continuation of [dispatch provenance and lineage](dispatch-association.md).
Follow-up: [authenticated token grants, uncertainty resolution and accounting](checkout-authorization-accounting.md) now supplies inactive internal implementations for those three next steps. The qualification below records the preceding transport increment.

Internal development APIs only. No checkout, route, worker, webhook consumption, default
transport binding or instrument authorization implementation is enabled by this increment.
All qualification uses synthetic data and faked HTTP; no merchant account was contacted.

## Concrete Authorize.net transport

`AuthorizeNetDispatchTransport` implements the existing `PaymentDispatchTransport`.
Both `payments.dispatch_enabled` and `payments.authorize_net_transport_enabled` default
to false. A trusted caller must explicitly construct/inject the adapter. Sale and
authorization additionally need an `AuthorizeNetInstrumentAuthorization` implementation;
none is registered. These switches and internal interfaces are not an authorization UI.

Before any HTTP, the adapter validates the exact persisted claimed attempt and its frozen
request/preparation, customer, merchant, environment, amount, reference and credential
fingerprint. A unique `payment_transport_invocations` row commits before remote work,
so a fabricated DTO or replay cannot invoke the adapter a second time. Uncertain outcomes
and crashes cannot release this claim. The outer dispatch action retains its own durable
attempt and minimal encrypted receipt. Neither layer retries mutations.

Sale/authorization accept only an ephemeral Accept.js opaque token supplied by the trusted
resolver, bound to this request fingerprint, preparation, account, customer and expiry.
No arbitrary profile ID, raw card, CVV, or inferred vault ownership is supported. The token
object refuses serialization and redacts debug output; its value is never persisted.
Capture and void target the exact derived original transaction. Refund uses the frozen authenticated account binding and reads the original settled
transaction with its exact current credentials, then reads only a
strictly masked `XXXXdddd` card value and transiently uses its final four digits with
`XXXX` expiration. No full instrument or masked card value enters the payment ledger.
Credentials, gates, token expiry and descendant lineage are rechecked before mutation.

The [current transaction contract](https://developer.authorize.net/api/reference/features/payment-transactions.html)
and [refund reference](https://developer.authorize.net/api/reference/index.html#payment-transactions-refund-a-transaction)
support original-ID capture/void and linked card refunds. The [official SDK schema](https://github.com/AuthorizeNet/sdk-php/blob/master/AnetApiSchema.xsd)
defines XML field ordering and response shapes. Synthetic fixtures are structural contract
evidence; activation still needs current provider/account fixture qualification.

The dedicated HTTP factory has no global event dispatcher or application middleware,
preventing credential/token request bodies from entering ordinary HTTP event logging.
Requests use fixed environment endpoints, bounded streaming XML, strict namespaces and
singleton fields, no redirects/decompression, and no mutation retry. Duplicate, ambiguous,
unsupported or mismatched responses remain unknown. Sanitized exceptions never carry raw
provider bodies or instruments. An observed gateway response is not bank settlement.

## Order-wide dispatch interlock

The dispatch action locks the commercial order before checking intent attempts. Any
attempt under another intent for the same order blocks new dispatch, including across
merchant accounts/environments and after a decline or uncertain outcome. A locking join
uses current MySQL rows even if an earlier read established a repeatable-read snapshot.
Exact replay still returns its original attempt without another invocation.

This is a conservative single-intent collection policy. It does not authorize split
payments, replacement intents, additional captures, recurring cycles or new collection
after a refund/void. Those need explicit obligation/retry rules. Existing same-intent
ancestor checks still prevent unrelated sibling operations from silently dispatching.

## Fresh financial evidence

`RecordPaymentFinancialObservationAction::execute(preparationId)` derives its target from
owned receipts and conflict-checked root/effect lineage, then performs authenticated
reporting itself. No caller amount, transaction ID or result DTO is accepted as authority.
Each entity gets a durable read request before HTTP. The latest requested read wins,
including across root/capture/void aliases; pending, failed, stale or conflicting reads
cannot fall back to an earlier good result. A storage failure leaves the request pending.

`ResolvePaymentFinancialEvidence` returns qualified **provider-reported** categories:
authorized, capture pending, settled, refund pending, refunded, voided or expired.
Authorizations are holds; capture pending is distinct from settlement. A refund is its own
entity. Void/expiry release an unsettled entity and never subtract settled revenue as if
a refund occurred. Amount checks, currency policy, owned capture evidence and retained
state history must agree. Unqualified currency, contradictory transitions, unsupported
states and unqualified chargeback/returned-item/reversal evidence remain explicit blocks.
Observed cancellation does not assert that a local void operation performed it.

The freshness policy is five minutes from the durable request start, not completion.
This is a local conservatism rule, not a provider consistency guarantee. Scope fingerprints
include all relevant dispatch/effect generations: a new claim, unknown result or new
mutation invalidates older amounts until covered by qualified evidence and a later read.
Current checks use locks before and after remote work; HTTP never runs inside a database
transaction. Immutable encrypted observations preserve the source and policy assessment.

## Order reconciliation

`ResolveOrderFinancialEvidence::execute(orderUuid)` returns a current read-only assessment
with explicit environment/account and currency. It counts each canonical account/entity
once, replacing the root view with its later capture/void view. Refund entities contribute
separately. Reported totals include authorization, capture pending, settlement, refund
pending, completed refunds, voids and `net_settled_minor = settled - refunded`. Pending
refunds remain visible separately and do not pretend to be completed credits.

The order assessment takes sorted account locks and the order lock, requires current
frozen scope, and refuses unresolved attempts, multiple collecting intents, stale/missing
entity evidence, currency conflicts or totals exceeding the immutable obligation. An
unqualified result returns no totals, rather than an apparently usable zero or stale
balance. The resolver makes no remote calls.

These are reported financial classifications, not bank-cash verification, spendable
refund capacity or an order-paid mutation. Existing operation uncertainty stays intact;
missing receipts are not recovered by guessing a transaction. No canonical events,
marketing deliveries, revenue postings, patient authentication or provider order state
change. Chargeback/refund loss allocation and durable accounting entries remain separate.

## Release and remaining work

Migrations `070000` and `071000` add empty financial read/observation and transport
invocation tables, with restrictive parent references and microsecond timestamps. They
do not import transactions or replay old operations. Preserve evidence and encryption/HMAC
keys through separately approved maintenance; removing a claim is not a retry strategy.

Next work is the concrete customer/quote/token authorization integration, current provider
fixtures, explicit replacement/sibling refund/recurring rules, uncertainty-resolution
contracts, and audited settlement/reversal postings. The inactive adapter can be tested
without enabling checkout. Deployment, served migrations, live account calls, actual
payments, receiver activation and marketing activation require separate authorization.

## Qualification

Order-level SQLite integration passed **4 / 91**; concrete XML-to-order reconciliation
passed **1 / 27**, with actual adapter/association/financial services and fake HTTP only.
The populated legacy schema upgrade passed **1 / 68**, preserving old records and leaving
all three new tables empty. Scoped formatting and patch checks passed.

Financial evidence passed **11 / 197 SQLite**, **2 / 43 MySQL**, and **12/12 process
scenarios**: newer unknown completion before older settled completion, newer pending
request, a newly claimed dispatch during a read, and a stale repeatable-read snapshot
followed by newer unknown evidence (three each). Order guard/aggregation MySQL passed
**2 / 43**, plus **6/6 process scenarios**: competing merchants for one order and a new
competing intent committed after the losing caller's initial snapshot (three each).
Temporary harnesses live under `/tmp/customer-mysql-20260914-uUkDgL/`, with separate
`financial_test`, `order_test` and `transport_test` schemas on its private Unix socket.
No TCP listener, served database or live gateway traffic was used.

Concrete transport passed **31 / 280 SQLite**, **2 / 26 MySQL**, and **8/8 process
scenarios**: duplicate direct invocation, timeout, process exit after fake POST and
concurrent credential drift during authorized preflight (two each). Each retained one
adapter claim; duplicate/timeout/loss cases produced one POST, credential drift produced
zero. Harnesses: `transport-race.php` and `run-transport-races.py` in the private test
directory. The disposable server was stopped after all three tracks completed.

Independent Astra review passed with no remaining findings, with **84 tests / 1,017
assertions** independently verified. Findings fixed before qualification included current
locking order checks, private mutation-channel ownership, pre-send lineage/gates/token
expiry, full retained terminal history, financial generation invalidation, and non-vacuous
malformed/refund fixtures. No runtime changes followed final review and race qualification. A final test-only
invocation counter hardened all nine negative refund fixtures; that subset passed
**9 / 81**, adding nine assertions to the prior full transport run.

Final integrated SQLite regression passed **1,731 tests / 8,724 assertions**. The older independent partial-void fixture was moved to a separate order under the new order interlock, with **11 / 189** focused lineage tests passing and reviewer approval. Application commit: `fcecd7544540a854c2ad373edaacb54ec8f96366`.
