# Checkout token authorization, uncertainty resolution and accounting

September 15, 2026 continuation of [gateway transport and financial reconciliation](gateway-transport-reconciliation.md). Internal implementation only: no public checkout route, default transport/authorization binding, worker, payment collection, provider mutation or marketing activation. Synthetic qualification uses fake HTTP and disposable databases.

## Authenticated token grants

`IssueCheckoutTokenGrantAction::execute(CheckoutTokenGrantData, bearer, opaqueToken)` requires the explicit `payments.checkout_token_authorization_enabled` gate (false by default). The bearer must identify a persisted Patient Sanctum token with `patient:*`, valid per-token/global expiry, current portal idle and absolute lifetime limits, verified email and current two-factor enrollment policy. No fresh second-factor challenge is introduced. The Patient must own the exact Customer and frozen commercial order. Anonymous `/checkout` cart/Lead matching does not establish this ownership and has not been wired to this action.

The request explicitly acknowledges the frozen quote fingerprint, amount, currency and `checkout_payment_v1` version. The immutable encrypted grant freezes current session, Customer/order, preparation, merchant/account/environment, credentials and quote scope. Only a keyed digest of the Accept.js opaque token is retained; neither raw token nor bearer is stored. Each preparation and token digest can have only one grant. Exact historical replay never renews expiry or consumption permission.

`CheckoutTokenAuthorization` implements the existing instrument authorization interface and holds the bearer/token only in memory. It refuses serialization and redacts debug output. For the exact persisted claimed dispatch request, it rechecks current session, ownership, quote, merchant and scope, then commits one immutable consumption before returning the ephemeral instrument. A crash after consumption does not release it. Concurrent attempts, drift, revoked/expired tokens and previously consumed grants fail closed. The grant proves local authenticated acceptance and one-use binding; the provider must validate the submitted nonce at dispatch. The grant expires after five minutes; this local policy does not prove provider nonce freshness. The transport retains its independent pre-send checks and invocation claim.

No card/PAN/CVV, arbitrary vault profile or raw instrument enters the ledgers. A future authenticated checkout UI must establish the explicit acknowledgement and ownership contract before integration; enabling a flag alone is insufficient.

## Supported uncertainty resolution

`ResolvePaymentUncertaintyAction::execute(operationUuid)` records one immutable audit only when the original uncertainty fingerprint, owned approved dispatch receipt, exact transaction/effect lineage and fresh qualified reporting evidence agree. Missing or unknown receipts cannot be recovered by guessing transaction IDs, amount, email or browser callbacks. Optional references retained in uncertainty evidence must match the owned source; they are not ownership authority.

The reporting request must begin at least one full second after the persisted uncertainty timestamp, because historical uncertainty timestamps have second precision. Financial generation fingerprints now include operation state and uncertainty fingerprint for every relevant attempt, including root/capture/void aliases. Evidence collected before uncertainty cannot be reused as if collected afterward.

`ResolvePaymentUncertaintyEvidence` returns `resolved_reported` only while current evidence remains qualified. A newer pending, failed, stale, currency-unqualified or conflicting read revokes current usability without erasing the audit. The historical operation remains `uncertain`; dispatch attempts and transport claims are unchanged. `execution_released` stays false. This overlay permits order financial assessment and accounting of supported outcomes; it never authorizes another collection, child operation or retry.

## Balanced control postings

`PostPaymentAccountingAction::execute(orderUuid)` holds the same sorted account mutexes and order lock as the current order financial resolver through journal commit. It accepts no caller-authored amount, account, transaction ID or reporting DTO. Current owned evidence and the frozen order obligation must qualify first.

A settled payment creates a journal debiting `gateway_clearing` and crediting `customer_payment_control`. A completed refund creates the reverse pair, linked to the actual same-order settlement journal. Refund totals cannot exceed that parent's amount. Authorizations, capture pending, refund pending, voids and expiry create no postings. These are operational control accounts; this increment does not infer revenue recognition, bank deposits, gateway fees, chargeback allocation or a spendable refund balance.

Each canonical account/environment transaction entity has at most one journal, independent of reporting observation IDs. Fresh reads and concurrent replays reuse the same immutable journal. Header and both balanced lines commit atomically; a line storage failure rolls everything back. Capture aliases cannot double count the original transaction. Refund journals retain their own entity and actual parent, currency, immutable economic fingerprint and encrypted source facts.

`ResolvePaymentAccounting` returns journal IDs and qualified reported totals with currency, environment and canonical account. Missing new postings return `posting_required`. Loss of current support returns `current_evidence_quarantined` without usable totals and preserves prior entries. No automatic reversal or destructive correction is invented for contradictory evidence. `bank_cash_verified` and `execution_released` remain false; Orders, provider state and canonical marketing events are unchanged.

## Release and remaining contracts

Additive migrations `080000`, `081000` and `082000` create empty grant/consumption, uncertainty-resolution and accounting journal/line tables. There are no automatic imports or producers. Preserve immutable claims/audits and encryption/HMAC keys; deleting a claim is not a retry procedure. See the [coordinated release checklist](../releases/customer-commerce-portal-2026-09-14.md).

Read-only inspection of local PRX source at `99e3ff134e40b890285820dfe7fca9640f37e491` found protocol-focused changes since `959cb7671`; it did not establish a new authenticated missing-receipt recovery or checkout/embed ownership contract. Recheck upstream changes when available, using [provider recovery requirements](../checkout/provider-recovery-contract.md). Neither local source nor a remote branch pin proves deployed API behavior.

Remaining work includes authenticated checkout UI/API integration, current provider/account fixtures, missing-receipt and embed correlation, explicit replacement/sibling refund/recurring obligations, vault ownership and bank/reversal reconciliation. Deployment, served migrations, live gateway/account calls, actual payments and receiver/marketing activation require separate authorization.

## Qualification

Final integrated SQLite regression: **1,779 tests / 9,308 assertions**, clean exit. Accounting **5 / 123** and uncertainty **8 / 145** passed focused SQLite. Populated upgrade **1 / 78** preserves legacy data and leaves all five added tables empty. Scoped Pint and patch checks passed.

Private MySQL authorization passed **5 / 62** across the original and session-policy delta runs, uncertainty **2 / 40**, and accounting **2 / 64**. Accounting's initial artisan invocation reported passing assertions with nonzero exit; the direct PHPUnit diagnostic rerun completed cleanly with no emitted issues. All **38/38** process scenarios passed: authorization **14** (grant/token uniqueness, one consumption, committed crash, session revocation and expiry after preload); uncertainty **12** (one audit and newer pending/failure/conflict after a stale snapshot); accounting **12** (one balanced entity journal and newer pending/failure/conflict refusing stale postings). No replay or expiry scenario released a payment claim.

Independent Astra review passed with no remaining findings. Independent successful runs included token/accounting/upgrade **37 / 469** before the final lifetime delta, accounting/uncertainty **13 / 264**, and final lifetime-focused **6 / 65**. These runs overlap and must not be summed. Review fixes included current portal session lifetime, uncertainty alias generation, bounded traversal, accounting currency/environment/account metadata, and meaningful durability/encryption fixtures. The reviewer did not independently verify the separate PRX source audit.

All HTTP and data were synthetic. The private MySQL server was stopped after all three schemas completed; its socket and pid file were verified absent. Served admin/portal/storefront baselines remain unchanged. No deployment, served migration, PRX edit, live account call, payment, receiver or marketing activation occurred.

Application commit: `d974e3dda791f470709f0181d19fec105c664a0b`. Disposable MySQL schemas are `authorization_test`, `uncertainty_test` and `accounting_test` on `/tmp/customer-mysql-20260914-uUkDgL/mysql.sock`, with no TCP listener. Temporary process harnesses stay outside the repository.
