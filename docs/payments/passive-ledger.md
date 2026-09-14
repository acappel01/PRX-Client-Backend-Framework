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
