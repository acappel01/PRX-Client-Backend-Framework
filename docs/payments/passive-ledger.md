# Passive payment intent and uncertainty ledger

Implemented September 14, 2026 in the isolated commerce branch. This is an internal
local bookkeeping foundation. There are no public routes, admin mutations,
observers, jobs, gateway requests, usable saved payment methods, verified monetary
transactions or paid-revenue projections in this increment. Existing checkout and
`ProcessCheckoutPaymentAction` remain unwired to it.

## Identities and scope

`payment_intents` freezes a UUID identity, existing Order/Customer/MerchantAccount
references and UUID snapshots, expected gateway/environment, positive integer
minor-unit obligation amount, uppercase three-letter currency and an intended
executor key. The amount is explicitly supplied by the trusted commercial caller;
it is not automatically copied from an Order or interpreted as captured funds.
It may describe part of an order obligation. Currency must match the Order; this
syntax/consistency check does not qualify a currency for a gateway.

Amounts are bounded to `1..999999999999` minor units. No float-to-minor-unit
conversion exists here. Existing gateway APIs still use decimal major-unit
strings; a separately qualified adapter must convert using the currency's scale.
No production currency-support or payment-capability promise follows from creating
an intent. The existing MerchantAccount/GatewayProvider/GatewayEnvironment types
remain the only gateway registry/configuration source.

A keyed fingerprint freezes the selected merchant's local account ID/UUID,
gateway/environment, endpoint, provider merchant mapping and selected gateway
credentials. It contains no stored plaintext credential. This detects local
configuration drift; it does **not** prove the actual remote merchant account,
provider mapping or receiver readiness. Credential rotation requires an explicit future rebinding/reconciliation policy
before new operations on affected intents. Application-key rotation also affects
request/evidence HMACs: retain the key or design a reviewed key-version migration
before expecting historical fingerprint replay across rotation. Historical intent/operation replays retain their
original identity; they do not silently adopt current routing or credentials.

The order fingerprint freezes ownership, currency/totals and priced item
identity, existing provider product references, quantity/prices/SKU/billing period. It also freezes the Customer's portal
association and the legacy Order patient pointer. A conflicting patient/Customer
association is rejected on preparation; detachment or reassignment blocks new
operations. Customers without a portal/chart remain valid for ordinary commerce.
Item names, chart information and addresses are not copied into this ledger.
This fingerprint detects priced-snapshot drift; it is not a price quote archive,
line-level payment allocation or authority that the quoted amount was paid.

`payment_operations` allows multiple separately identified typed operations per
intent: `sale`, `authorize`, `capture`, `refund`, `void`. Every operation freezes
its own executor key, amount and optional original local operation. Capture must
name an authorization; refund must name a sale/capture; void must name a
sale/authorization/capture from the same intent. Sale/authorization have no
original. An amount cannot exceed the parent operation or intent amount.
Executor keys are bounded internal identifiers selected by a trusted coordinator,
not public authorization, a scheduler ownership transfer or proof of a provider
contract. A prepared operation is never permission to execute.

The local operation UUID is global and durable: changing amount, purpose,
executor, original or intent under the same identity conflicts. The immutable
intent binds each operation to one merchant/environment/account configuration;
identical remote reference strings on different intents are not merged. Actual
same-account deduplication across duplicate MerchantAccount rows still requires
an explicit canonical account mapping before real gateway ingestion.

## Internal actions and state

- `PreparePaymentIntentAction(PaymentIntentData)` validates current ownership and
  explicit merchant/environment, then atomically inserts or replays the intent.
- `PreparePaymentOperationAction(PaymentOperationData)` checks the frozen scope,
  original lineage and bounds, then atomically inserts or replays an operation.
  A new operation is refused while any operation on that same intent is uncertain.
  Multiple intents can describe one Order; this is not an Order-wide executor
  interlock. A fresh intent/operation UUID never grants permission to retry money.
- `RecordPaymentUncertaintyAction(PaymentUncertaintyData)` locks the intent and
  operation and records one immutable, encrypted observation. Replays must match
  the original evidence exactly, including occurrence time.

These are trusted internal producer APIs. A model ID or operation UUID does not
perform customer/staff authorization. Future callers must establish authorization
and the commercial reason for each operation before invoking them. There are no
new API permissions or browser-callable routes.

Operations have only `prepared` and `uncertain` states. The sole state transition
is prepared → uncertain; uncertainty can be retained after merchant/ownership
configuration drift so that loss of configuration cannot erase an unresolved
outcome. Replaying preparation returns the same operation, including its current
uncertain state. It cannot reset the operation or create a replacement attempt.
There is no confirmed/failed/resolved state or resolution action yet.

Evidence is an allowlist: a typed reason (`transport_timeout`, `connection_lost`,
`unverified_response`, `reconciliation_required`), occurrence time and optional
bounded gateway transaction/original reference strings. These references are
**unverified descriptive evidence**, not ownership proof, reusable vault tokens,
settled transaction IDs or permission to mutate a gateway. The DTO has no raw
response, nonce, PAN, CVV, bank account, vault profile or arbitrary metadata field.
Trusted producers must supply transaction references only; the syntax filter is
not an arbitrary-string secret classifier. Evidence and fingerprints are hidden
from generic model serialization. No data is exported to canonical marketing
events, commissions, merchant volume or Order/Lead payment status.

Each action owns a transaction. Unique constraints and savepoint/current-read
recovery handle duplicate writers. Intent locking serializes operation preparation
with uncertainty recording. Model hooks reject ordinary identity/history edits
and deletes; foreign keys restrict deletion of referenced parent records. Query
builder/raw SQL bypasses model hooks and is reserved for separately reviewed
maintenance. No purge or automatic migration backfill is added.

## Remaining contracts before financial execution

The existing gateway abstraction alone is insufficient to enable collection:

1. `ProcessCheckoutPaymentAction` passes a MerchantAccount UUID where gateway
   implementations resolve a numeric primary key. Its vault payload names do not
   consistently match the gateway-specific profile fields. It has no callers in
   app/routes/tests as inspected for this increment; keep it unwired until fixed
   with an explicit operation-aware adapter.
2. Existing `PaymentResult` has a generic success/status flag, no durable local
   operation identity and no reliable uncertain-versus-declined distinction.
   NMI's transport catch currently returns a failed result with a message claiming
   the card was not charged; a timeout cannot establish that. Existing comments
   also conflate authorization/capture/settlement. None of those assumptions is
   imported into this ledger.
3. Gateways expose mutations but no canonical transaction-read/reconciliation
   interface or authenticated durable financial inbox. Square generates a new
   idempotency key per call; a caller's durable operation identity is not threaded
   through the common interface. Establish provider-specific identity, replay,
   uncertainty and authenticated current-read contracts before any executor.
4. Verify the real gateway account/environment and explicit local-to-provider
   mapping. Local configuration hashes do not establish that multiple merchant
   rows represent the same real account. Bind receiver-selected account identity
   to signed notifications and canonical reads before verified transaction
   projection or shared reversal deduplication.
5. Build durable evidence ingestion and verified transaction/original lineage,
   captured versus settled projections, refund/chargeback overlap accounting and
   concurrent remaining-refundable-balance enforcement. This passive ledger does
   not yet enforce cumulative refund balances or prove any prior operation
   succeeded; several prepared intentions do not constitute financial effects.
6. Vault results differ: AuthNet needs customer + payment-profile identities;
   Stripe and Square current vault results may expose a customer ID rather than
   the usable instrument ID. Build merchant/customer-scoped verified readiness,
   explicit selected-card/quote/consent binding and method capabilities before
   saved-card use. Receipt-only references cannot imply reusable instruments.
   ACH remains a separate unqualified capability.
7. Add verified outcomes, selected executor claims and uncertain reconciliation
   before routing money. Recurring cycle identity, in-flight ownership transfer,
   item allocation and financial destination delivery remain separate increments.

The detailed upstream requirements remain in the web
`docs/runbook/10-prx-commerce-contract-remediation.md`. Proposed APIs there are not
assumed deployed. This ledger does not implement PRX contract remediation or
activate a payment path.

## Isolated qualification

The focused ledger suite covers replay/conflict, explicit owner/account/currency
and integer bounds, credential/order/item/legacy-account drift, original operation
lineage, per-operation executors, immutable encrypted uncertainty with subsecond
occurrence precision, unresolved-operation blocking, and no HTTP/driver or revenue
projection. Thirteen focused tests passed on SQLite and disposable MySQL; related
payment/routing coverage also passed. Eighteen two-process MySQL races passed:
three identical and three conflicting writers each for intents, operations and
uncertainty evidence, with a repeatable-read snapshot established before the
winning commit. Each retained one logical identity/evidence record. All fixtures
were synthetic, the private MySQL listener was socket-only and no gateway
qualification or payment was performed.

## Follow-up outcome observations (September 14 continuation)

`RecordPaymentOutcomeObservationAction(PaymentOutcomeObservationData)` appends
allowlisted **unverified reports** against an existing operation. Its caller supplies
a durable observation UUID, operation UUID, frozen local MerchantAccount UUID,
gateway/environment, bounded internal source key, typed reported outcome,
subsecond UTC occurrence time, optional transaction/original/event references and
an optional nonnegative integer amount/currency pair. This action has no public
route, receiver, worker, gateway call or authentication adapter. Trusted callers
must establish authorization separately; naming a source cannot authenticate it.

Only the observation UUID deduplicates ingestion. Identical replay returns the
same row; changing any evidence under that UUID conflicts. A new UUID with the
same source-event reference remains another report. This is not canonical
remote-event inbox deduplication or shared-account transaction deduplication.
The local account UUID/provider/environment must match the intent's frozen scope;
this does not verify the actual remote merchant. Duplicate local account rows are
not merged. Account mapping and receiver ownership must be qualified separately.

Evidence remains encrypted and hidden from generic model serialization. No raw
provider payload, arbitrary metadata, clinical data, payment token, vault profile,
card or bank fields are accepted. Reference syntax is not a secret classifier:
trusted producers must supply only appropriate operational references. Ordinary
model updates/deletes are blocked, with a restrictive operation foreign key;
query-builder/raw SQL maintenance can bypass hooks and requires separate review.
The unique UUID, transaction/savepoint and locking current-read recovery preserve
replay semantics for duplicate writers.

Reports survive later account credential or order drift. Multiple conflicting or
out-of-order reports are retained without choosing a winner. Reported amount and
currency may disagree with the intent because rejecting that discrepancy would
lose evidence. `captured`, `settled`, `refunded` and other enum values describe the
producer's claim, never a verified local result. No observation updates operation
state, resolves uncertainty, authorizes another attempt, changes refund balances,
creates a transaction, exports events or projects paid revenue. This increment
supplies durable follow-up evidence, not a reconciliation decision.

### Concrete Authorize.Net adapter contract still required

Read-only investigation checked local `AuthorizeNetGateway::ctx()` and
`executeTransaction()` plus installed SDK reporting types on September 14, 2026.
The driver selects a local numeric MerchantAccount primary key and environment,
builds merchant authentication, and invokes mutation controllers. It does not
implement transaction reporting, a signed receiver, canonical account binding or
a durable operation-aware outcome verifier. `PaymentResult` normalization is not
sufficient evidence of current settlement.

Authorize.Net says notifications are historical triggers; use authenticated
`getTransactionDetails` for current status and reject invalid notification HMACs.
The receiver must retain the selected account/environment and verify the exact
raw body using that account's Signature Key before accepting a durable event.
[Official webhook contract](https://developer.authorize.net/api/reference/features/webhooks.html).

The next read adapter needs a receiver-selected, explicitly mapped account;
`GetTransactionDetailsRequest` with merchant authentication and transaction ID;
and exact checks of returned transaction ID, type, status and original reference.
Keep authorized, captured-pending-settlement, settled and refund states distinct;
unknown/error/review statuses cannot become success. Preserve failed reads for
retry without issuing a mutation. [Official API reference](https://developer.authorize.net/api/reference/index.html#transaction-reporting-get-transaction-details).

Installed `TransactionDetailsType` exposes `getTransactionStatus()`,
`getRefTransId()`, `getAuthAmount()` and `getSettleAmount()`; its amount annotations
are floats and it has no currency getter. The adapter therefore needs a qualified
exact decimal parsing path and merchant currency authority, not float rounding or
blindly trusting the intent currency. `getMerchantDetails` is a candidate account
configuration read, not yet a qualified proof that two configured local/PRX rows
represent the same account. [Official reporting capabilities](https://developer.authorize.net/api/reference/features/transaction-reporting.html).

Before enabling any verified projection, qualify duplicate local account mapping,
wrong-account reads/signatures, original-lineage mismatches, same transaction ID
in different accounts/environments, amount/currency mismatch, timeout/missing
transaction, unknown status, delayed refund/capture ordering and concurrent refund
balance enforcement. No credential or remote account was queried in this work;
no claim that these prerequisites are satisfied follows from the SDK's existence.

### Follow-up observation qualification

Seven focused observation tests plus thirteen existing passive-ledger tests passed
on SQLite and disposable socket-only MySQL (20 tests, 204 assertions per engine).
Six two-process MySQL races (three identical, three conflicting observation UUID
writers) retained one row and the expected replay/conflict result with an older
repeatable-read snapshot established before the winning commit. Fixtures used
synthetic local customers, orders, accounts and references; no gateway was called.


## Read-only Authorize.Net account and transaction qualification

The September 14 follow-up adds `VerifyGatewayAccountBindingAction` and
`ReadAuthorizeNetTransaction`; neither has a route, job, receiver, schedule or
checkout caller. Tests use only synthetic `Http::fake` responses. No merchant
account was contacted, no existing deployment mapping was inserted, and no
payment, vault, ledger outcome or revenue state is changed.

`GatewayAccountBindingData` requires a selected local merchant ID, explicit
environment, expected real Authorize.Net gateway ID, expected currency, and an
exact expected `provider_merchant_profile_id` (including null for local-only).
The action authenticates `getMerchantDetails` with that row's login and
transaction key. Returned gateway ID and exactly one supported currency must
match the supplied facts; account test mode, missing/duplicate fields, unsupported
currency, inactive rows and custom endpoints fail closed. Signature Key is not
required for reporting; its configured value is included in the frozen scope but
is not verified by this read.

The immutable `gateway_account_bindings` row references the existing merchant
registry and freezes its UUID, environment, real gateway ID, current currency,
credential/routing fingerprint and encrypted explicit provider-row mapping.
The canonical key hashes gateway/environment/verified gateway ID. Different local
rows share that key only after each credential set independently returns the same
remote identity in the same environment. Different environments remain separate.
The provider-row mapping is an operator-supplied association, **not proof that the
PRX row actually belongs to that gateway account**. No deployment-specific mapping
is a generic default. Account/configuration drift rejects reads and rebinding;
rotation/rebinding needs a separately reviewed policy. Application-key rotation
also changes the frozen HMAC continuity contract.

Binding verification performs HTTP outside database transactions, then locks the
merchant and checks continuity before inserting/replaying the unique local-row
binding. Concurrent duplicate writers serialize on that existing merchant.
Both public orchestration entry points reject an existing caller transaction so
repeatable-read snapshots and transaction retries cannot hide drift or repeat IO.
Ordinary binding updates/deletes are refused, and the foreign key restricts
parent deletion; query-builder maintenance can bypass model guards and is not an
operator workflow.

`GatewayTransactionReadData` requires the selected binding, transaction ID,
expected transaction type, exact expected original reference (including null),
expected positive minor-unit amount and currency. The service checks current
credential/config continuity, rereads account identity/currency, reads transaction
details, and checks continuity again. ID/type/original/status vocabulary and
amount must match; refund requires an explicit original. Authorizations,
capture-pending-settlement, settled, voided, review/error and refund statuses
remain distinct strings. The returned DTO contains only allowlisted reporting
fields and a read timestamp. Matching these caller expectations does **not**
establish local operation ownership, a cumulative refundable balance, finality,
paid order status or permission to retry a monetary request. Unknown or
unsupported reporting combinations fail closed.

### Currency qualification limit

Reporting XML retains exact decimal strings until integer conversion, avoiding
the installed SDK's float amount types. The parser accepts only unsigned ordinary
decimal amounts with at most two decimal places and at most 999999999999 minor
units. The initial allowlist is USD/CAD/GBP/DKK/NOK/PLN/SEK/EUR/AUD/NZD, all using
two minor-unit digits; this is a parser boundary, not merchant processing readiness.
The account must report exactly the expected one of these currencies.

However, current merchant currency configuration does not prove a historical
transaction's currency. `TransactionDetailsType` has no currency field. Therefore
the DTO explicitly returns `currency_authority=current_merchant_configuration`
and `transaction_currency_verified=false`; its minor-unit values are conversions
under that declared scope, **not verified historical monetary facts**. An
independent immutable-account-currency or per-transaction currency contract is
still required before financial projection. No such contract was assumed here.

### Transport and remaining activation contracts

The XML adapter posts only `getMerchantDetailsRequest` and
`getTransactionDetailsRequest` to the fixed official environment endpoints.
It uses escaped XML credentials, TLS, bounded connection/read/overall timeouts,
no redirects or decompression, identity encoding and a streaming 256 KiB cap.
Streams close on success/failure. DTD/entity declarations, foreign namespaces,
ambiguous required fields and malformed/error responses fail closed. Raw XML,
card/profile/contact fields and credential-bearing errors are neither persisted
nor logged by these services. Only the transport/parser sees raw provider XML;
DTOs crossing its boundary contain the explicit allowlist. Future observability
must continue excluding HTTP bodies and credentials.

No webhook signature helper is shipped: official webhook documentation establishes
raw-body HMAC-SHA512 but this increment did not independently qualify the key
encoding and a provider-issued verification vector. A selected-account signed
receiver, durable inbox/deduplication, local operation/original lineage binding,
transaction currency authority, settlement/refund accounting and uncertainty
resolution remain required. NMI and other gateway reads are also unimplemented.

Primary references inspected September 14, 2026:
- [Authorize.Net account and transaction reporting reference](https://developer.authorize.net/api/reference/index.html#transaction-reporting-get-merchant-details)
- [Reporting capabilities](https://developer.authorize.net/api/reference/features/transaction-reporting.html)
- [Official PHP SDK transaction reporting type](https://github.com/AuthorizeNet/sdk-php/blob/master/lib/net/authorize/api/contract/v1/TransactionDetailsType.php)
- [Official webhook authentication contract](https://developer.authorize.net/api/reference/features/webhooks.html)

The full API-reference page exceeds the browser extraction limit; its merchant
example and installed SDK XML metadata were checked directly. No sandbox or live
provider response was represented as qualification evidence.


### Account/read qualification results

The focused synthetic SQLite suite passed **13 tests / 131 assertions** covering
mapping identity/replay, duplicate local account semantics, environment separation,
credential/config drift before and during reads, explicit provider mapping,
exact decimal boundaries, status/original/type/amount mismatches, test mode,
currency ambiguity, DTD/namespace/duplicate XML fields and containers, response
bounds/compression/redirects, sanitized errors, no caller transactions and no
financial projections. An independent code review found no payment findings.

The five database-relevant tests also passed on disposable socket-only MySQL
(**5 tests / 26 assertions**): binding replay, duplicate account identity,
credential drift, drift during a remote read, and caller-transaction refusal.
Six two-process MySQL binding races passed: three identical and three conflicting
remote account identities retained exactly one binding per local merchant, with
identical requests replaying one ID and conflicts refusing the loser. Their
barrier ran inside the synthetic HTTP fake before the local write, with no outer
database transaction. These checks used only `gateway_read_test` on the isolated
`/tmp/customer-mysql-20260914-uUkDgL/mysql.sock`; no served schema or actual gateway
was contacted. Parser-only cases were qualified on SQLite, not represented as
additional MySQL cases.
