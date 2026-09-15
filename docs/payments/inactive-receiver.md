# Inactive notification inbox and payment reference correlation

Implemented in isolated development September 15, 2026. These are trusted internal
APIs, with no public route, controller, worker, scheduler, gateway enrollment or automatic
checkout integration. Callers must establish authorization. No live account or live webhook delivery was used in qualification; a published
historical notification vector was reproduced offline. See the [investigation](receiver-contract-investigation-2026-09-15.md)
for signature provenance and outstanding activation requirements.

## Operation references

`ReservePaymentOperationReferenceAction::execute(operationUuid, bindingId)` reserves
one immutable reference for the full local operation identity and exact verified local
account binding. New reservations require current intent ownership/order/merchant scope,
matching account/environment/currency and a prepared operation with no uncertainty on
its intent. Generated references are 20 lowercase hexadecimal characters from ten random
bytes. The canonical account/reference unique constraint detects collisions; bounded
savepoint retries allocate another value. No UUID truncation or caller-supplied reference
is used. Canonical account identity already includes gateway and environment; independently
verified duplicate local account rows share its collision namespace.

Existing same-operation/same-binding reservation returns the original identity, including
after uncertainty or configuration drift. Different binding replay conflicts. Historical
replay is not readiness, a dispatch receipt or permission to retry a charge. The reference
is hidden from generic model serialization and must be deliberately read by a future
qualified adapter. It is opaque operational metadata, not a payment token or secret.
Ordinary changes/deletes are refused; foreign keys restrict deletion of parents. Trusted
raw-SQL maintenance bypasses model guards and needs its own preservation procedure.

`payment_operation_references` does not claim a gateway transaction or mark an operation
sent. A future executor must transmit the reserved reference as its request `refId` and
persist its own dispatch/uncertainty evidence before recovery can attribute effects.
No existing gateway mutation was wired or changed. References cannot be assigned after
a historical notification to manufacture ownership.

## Explicit reporting comparison

The reporting DTO now exposes nullable `merchant_reference`, parsed only from direct-root
`getTransactionDetailsResponse.transrefId`. It is distinct from request/response `refId`
and transaction `refTransId` lineage. At most one text-only value with no attributes is accepted in the expected namespace,
with a bounded ASCII reference of 1–20 characters.
Missing values remain null; ambiguous/malformed values refuse. Callers may supply
`expected_merchant_reference` for an exact comparison; absence then refuses too.

`CorrelateAuthorizeNetOperation::execute(referenceId, transactionId)` performs explicit
account and transaction reporting outside database transactions. It compares the reserved
reference and the operation's expected type/amount/current account currency, with current
locking checks of local scope before and after HTTP. Caller-held transactions refuse
before IO. It currently supports sale and authorization only. Capture/void actor identity
and refund original-operation mapping need separate qualified contracts; those purposes
refuse rather than accepting supplied lineage or matching amounts alone.

The returned status is `reference_matched_only`, with `operation_verified=false`.
Transaction currency retains `current_merchant_configuration` and
`transaction_currency_verified=false`. Results do not persist a transaction binding,
consume inbox entries, choose between conflicting reports, resolve uncertainty, update
Order/Customer/operation state, calculate refund balances or publish revenue/events.
Repeated comparisons can expose different transaction IDs with the same reference; no
financial winner is inferred. An authenticated matching reference is one piece of evidence,
not proof that a particular local executor dispatched or completed the payment.

## Inactive authenticated intake

`ConfigureAuthorizeNetReceiverAction` explicitly binds a configured receiver to a verified
local gateway account binding, environment, configured webhook identity and key version.
The exact Signature Key text is encrypted and tied to current merchant configuration.
This does not create a provider webhook or expose a receiving URL. Duplicate configuration
replays locally. Account credential/key/config drift refuses; no automatic rotation or
fallback to another merchant/key exists. Current provider fixture qualification remains
required before public activation; the historical text-key vector is not live readiness.

`ReceiveAuthorizeNetNotificationAction` accepts one selected receiver, raw body, signature
header values and content encoding. It authenticates exact bytes using the pinned
`authorize-net-webhook-text-key-v1` contract before JSON parsing. It rejects missing or
multiple headers, malformed digests, unsupported compression, oversized bodies, duplicate
decoded JSON keys and ambiguous envelopes. Body syntax/depth/node limits are bounded.
There is no socket reader in this internal API; a future HTTP wrapper must additionally
bound incoming stream size/time and preserve exact bytes and header cardinality.

The inbox retains only encrypted notification/event/webhook/entity/reference fields,
keyed body/notification digests and immutable local receiver/account/environment evidence.
No raw body, card/bank/contact fields, amount/status claims or signature headers survive
in stored receipts or logs. The caller-owned input must likewise be kept out of logs,
queues and traces. Valid bounded payment event notifications can be retained even when
no operation matches; notification authenticity never establishes a financial effect.

`gateway_notification_inboxes` deduplicates canonical account/environment/notification
identity across duplicate local merchant/receiver rows. Same identity and exact raw-body
digest replays; changed body produces retained, deduplicated conflict evidence in
`gateway_notification_conflicts`. Neither changes the first receipt nor chooses a winner.
Normal model updates/deletes are refused and parent foreign keys restrict deletion.
Application-key rotation affects HMAC lookup continuity and encrypted history; preserve
keys or design a reviewed key-version migration before rotation. Do not purge replay
identities merely because detailed evidence is retired.

Intake refuses caller-held transactions and returns only after its own commit. A future
HTTP wrapper may acknowledge accepted/duplicate receipts with 200 after that return;
storage failure remains retryable. No HTTP acknowledgement is implemented by these
internal actions. Provider retries can span days, so event time is not treated as a short
signature expiry window. Delayed notifications and authenticated pings remain inactive.

## Remaining work and release boundary

All new migrations add empty local tables; no receiver/reference/backfill is populated.
Release/rollback must preserve any retained history and key continuity. Existing served
installations, portal authentication and PRX source remain unchanged.

Before activation, qualify a current provider signature fixture and endpoint lifecycle,
receiver-selected live account/webhook mapping, replay retention/key rotation, bounded
HTTP intake and explicit worker controls. Before any monetary projection, add durable
transaction/operation binding and conflict policy, dispatch provenance, supported lineage,
scoped currency authority, settlement/refund accounting and uncertainty resolution.
Vault readiness and payment execution remain separate. Configuring these internal models
never activates those capabilities.

## Qualification

The integrated synthetic SQLite suite passed **1,634 tests / 7,522 assertions** before
the final storage-failure fixture addition. Registry tests passed **11 / 83**;
reporting passed **17 / 176**. Independent Astra repeated registry/reporting together
(**28 / 259**) and found no remaining issues. Inbox durability and camel-case event
findings were fixed before the integrated run. The populated-schema upgrade rehearsal
passed **1 / 52**, preserving existing rows and keeping all four new tables empty.
HTTP is faked; no live delivery or payment occurs. Final database concurrency and
storage-failure evidence are recorded in the coordinated release checklist.

Final independent Astra review: **PASS, no remaining findings**. The reviewer ran
**38 tests / 610 assertions** across registry/reporting and inbox, including final
receipt/conflict storage-failure triggers. The inbox suite alone passed **10 / 351**.
The full-suite count above predates the added failure fixture, a final strict numeric
JSON-type correction, and a shorter explicit foreign-key name required by MySQL.
The final inbox suite and migration review qualify those deltas separately; the
upgrade test passed again after the schema correction.

Disposable MySQL qualification passed registry **3 tests / 27 assertions** plus **8/8**
separate-process scenarios (same-operation replay, conflicting binding, forced reference
collision and stale-snapshot credential drift). Inbox passed **2 / 47** plus **15/15**
process scenarios (same receiver replay, duplicate local receivers, changed-body conflicts,
account isolation and stale-snapshot credential drift). Initial schema setup failures
from the generated foreign-key name were corrected and all selected tests rerun.
Temporary guarded harnesses are under `/tmp/customer-mysql-20260914-uUkDgL/`:
`registry-race.php`, `run-registry-races.py`, `inbox-race.php`, `run-inbox-races.py`.
They are session evidence, not shipped tooling. Only `registry_test` and `inbox_test`
on the socket-only disposable server were used; the server was stopped afterward.
