# Authorize.Net receiver contract investigation — September 15, 2026

Investigation only against admin `044272165324f226915d791f135738734039f007`.
No application behavior, schema, gateway account, deployment or PRX source was changed.
Public documentation/source and local synthetic calculations are evidence; no live
account requests, payment attempts, webhook enrollment or support messages occurred.

## Findings and recommended next increment

The previous three gaps are narrower. Original-request correlation is documented and
available in the installed SDK. A provider single-currency-account policy supplies a
possible scoped currency authority. Webhook signature encoding has reproducible
historical evidence, with a remaining distinction between observation and a current
formal provider contract. None supplies missing historical local operation ownership.

Proceed next with an inactive, internal receiver foundation: explicit account/key scope,
strict raw-body authentication, a minimal durable notification inbox, and a preallocated
operation-reference registry. Extend the reporting parser to expose original-request
correlation separately from transaction lineage. Keep unmatched/conflicting evidence
visible and keep financial projection, payment execution and public activation separate.
This is a proposed implementation boundary, not a claim those components exist.

## Original request correlation: documented; not wired locally

The [official transaction-details reference](https://developer.authorize.net/api/reference/index.html#transaction-reporting-get-transaction-details)
defines root `getTransactionDetailsResponse.transrefId` as the original request's
merchant reference. The installed official SDK exposes it in
`GetTransactionDetailsResponse.php:75`; `AnetApiSchema.xsd:3607` makes it optional
and limits it to 20 characters. The currently published reference has the same limit.
Do not confuse these fields:

| Field | Meaning for matching |
|---|---|
| Mutation request `refId` | Merchant-supplied request reference |
| Reporting response root `transrefId` | Original transaction request reference |
| Reporting response root `refId` | Echo for the reporting request itself |
| Transaction `refTransId` | Related/original gateway transaction, not our operation UUID |
| Webhook `payload.merchantReferenceId` | Echo of API request reference; a candidate lookup after authentication |

The [webhook contract](https://developer.authorize.net/api/reference/features/webhooks.html)
documents that webhook reference relationship and recommends a reporting read for
current transaction state. Missing references must remain unmatched. API `userFields`
are response pass-through fields which the provider does not store; they cannot replace
a durable recovery handle. Invoice numbers are not an ownership or uniqueness guarantee.

Current admin `AuthorizeNetReportingClient::transaction()` only selects children of
`transaction`; it ignores root `transrefId`. `AuthorizeNetGateway::executeTransaction()`
sets authentication and transaction data but no request reference. Its interface has
no durable operation argument. The existing passive operation UUID and unverified
observation references therefore cannot establish a historic transaction association.

Proposed matching contract (local design): reserve a collision-checked opaque reference
of at most 20 characters before dispatch, unique within canonical gateway account and
environment, immutably linked to the full operation UUID and expected execution scope.
Never truncate a UUID and assume uniqueness. On a signed notification, select the
receiver's account first, read the transaction under that account, and require matching
current-read reference, operation purpose, amount/currency authority and original lineage.
Absent references, duplicate reference matches, conflicting transaction IDs or mismatch
must not resolve uncertainty. A known durable transaction binding can support later
state observations; matching current state alone cannot prove which capture/void actor
performed a particular local operation.

This reference is correlation, not gateway idempotency. The
[transaction settings documentation](https://developer.authorize.net/api/reference/features/payment-transactions.html)
defines a finite duplicate window, at most eight hours. It cannot make an uncertain
mutation safe to repeat indefinitely. Refunds have distinct transaction IDs; capture
and void operate on existing transactions. Do not enforce one remote ID per local
operation as a universal rule or count each notification as another financial effect.
Detailed capture/void reporting transitions still need purpose-specific fixtures before
operation resolution; unknown combinations remain refused by the existing read adapter.

## Currency: scoped policy evidence, distinct from a transaction field

Authorize.Net's [supported processors/currencies policy](https://support.authorize.net/knowledgebase/article/000001210/en-us)
states that an account starts with one currency and another currency needs another
account. Its surrounding processor coverage is North American USD/CAD. The
[sandbox FAQ](https://developer.authorize.net/support/common-setup-questions.html)
also says changing currency requires a new sandbox account.

Inference: a reviewed single-currency-account policy can support account-derived
historical currency within its documented scope, alongside authenticated account
identity and a singleton currency. This improves on the previous absence of a located
policy. It is not a currency observed in the transaction response and does not justify
changing all ten supported parser currencies to verified historical currency.

Both installed SDK 2.0.4 (`8555cc245953dd0ac57f7ea424a5572eae4c7191`) and the
[current official schema](https://github.com/AuthorizeNet/sdk-php/blob/master/AnetApiSchema.xsd)
lack currency fields in transaction details, summaries and batch details. Current
`currency_authority=current_merchant_configuration` and
`transaction_currency_verified=false` remain accurate descriptions of shipped code.
A later scoped policy must record its version/basis and reject unsupported rails,
account configurations, multiple currencies and identity/configuration drift.

The official [transaction download guide](https://support.authorize.net/knowledgebase/article/KA-05372/en-us)
and its [format attachment](https://support.authorize.net/_entity/annotation/9db71d8a-8211-f011-9988-6045bd076042)
describe distinct authorization and settlement currency columns with transaction and
reference IDs. This is a possible separate reconciliation evidence source, requiring
authenticated export provenance and a strict allowlist. No export was obtained and no
importer is proposed as an automatic workaround.

For broader currency support, the remaining provider question is whether the same
gatewayId/environment can retain transaction history through currency/processor changes,
and whether all historical authorization/settlement amounts share its current singleton
currency across legacy/international/alternative-payment accounts. If not, request the
authoritative per-transaction fields and their provenance. No message was sent.

## Read-only PRX comparison and remaining ownership boundary

Provider source is now `959cb76715d99c6e8ae6c0eb8c91e24ef2b2bb2e`, compared with local
`origin/production` reference `9bb2f71596369ac205b1b30f0e7a7a6f4c4436e3` before inspection.
These are source snapshots; the remote-tracking reference is not proof of deployed code.
This limited financial inspection does not requalify the separate checkout/embed contract.

At this source HEAD, PRX `AuthorizeNetGateway::applyRefId()` supplies the trailing 20
characters of a caller key for sale/authorization. Capture does not use that helper.
This is not a bridge to this admin's local operation registry. Its webhook controller
also permits missing signatures and can accept unmatched signatures under configuration
conditions; its service initially looks up a transaction by gateway transaction ID alone.
Those are source findings, not evidence of a live exploit or a deployed configuration.
Do not copy these fallbacks into a verified receiver. PRX mapping ownership still needs
an independently authenticated account association and an immutable execution reference.

## Signature and replay: reproducible historical evidence; activation unqualified

The official webhook prose specifies HMAC-SHA512 over the body with the Signature Key
and `X-ANET-Signature`, but does not explicitly define key-byte encoding. A
[first-person published experiment from March 8, 2017](https://community.developer.cybersource.com/t5/Integration-and-Testing/HMAC-SHA-512-hash-not-matching-in-webhook-callback/td-p/57162)
contains a complete 338-byte body, 128-character key and expected signature. Its author
is displayed as an All Star, not an independently verified provider employee. This is
primary historical experimental evidence, not a current normative API guarantee.

Local reproduction in both PHP and Python matched the published digest using the
literal key text. Decoding it to 64 binary bytes did not match. Three negative checks
rejected a trailing newline, a changed decimal spelling and JSON reserialization.
The matching digest is
`9607C69D2734514554CE13D923D19939563F2FD6567D239F49F0DF0D08865632A0FB98061B4620CC52C47BB8BF6E2B69A15A67E4C98E81D7D65111076C515C6A`.
The session reproduction script is `/tmp/gateway-signature-vector-20260915.py`;
its public fixture is recoverable from the linked original. It performs no network IO.

The [owned PHP transaction-hash sample](https://github.com/AuthorizeNet/sample-code-php/blob/6f14b0f30e301f6de35bba14e7edef9f3a5b191a/Sha512/compute_trans_hashSHA2.php)
decodes the key, but hashes a caret-separated transaction-response tuple. It is a
different protocol. The [owned webhook sample](https://github.com/AuthorizeNet/webhooks-sample-app/blob/96b94865f7e108fb26b2325e83a70c26c4b01716/server.js)
has no HMAC verification; it cannot qualify a receiver. Do not combine those samples
into an assumed webhook contract or accept both encodings as a fallback.

Proposed inactive receiver rules (our design, not additional provider promises):

- Select one configured receiver/account/environment/key version before authentication;
  do not try every merchant key or allow missing keys/signatures. Bind configured webhook
  identity after authentication. Preserve key text bytes exactly under an explicit
  text-key contract; a separately authorized current provider fixture must qualify
  that selected contract before public activation or a live verified-receiver claim.
- Bound raw body size/time, refuse unsupported encoding and duplicate signature headers,
  require the complete `sha512=` plus 128 hexadecimal digest characters, and compare in
  constant time. Authenticate exact bytes before JSON parsing. Refuse duplicate JSON
  keys and ambiguous envelope shapes. Do not persist raw bodies or log secrets/payloads.
- Atomically retain minimal encrypted evidence and a keyed body digest, deduplicated by
  canonical account/environment/notification ID. Changed body under the same identity
  is a conflict. Separate notification deduplication from transaction effect accounting.
- Acknowledge 200 only after durable acceptance (or confirmed duplicate). Storage failure
  stays retryable. Perform reporting outside the acknowledgement/storage transaction.
  Valid pings and unknown/unmatched transactions cannot become financial effects.
- Use durable replay protection rather than an invented short event-time cutoff. Provider
  retries can span days; a signed historical event is not fresh state. Record key-version
  continuity and retain deduplication tombstones without repeating financial processing.

## Review and qualification

Independent review covers source provenance, narrow currency scope, correlation-field
semantics and the proposed inactive boundary. No newly selected Astra model identity
is claimed for the reused reviewer thread. Final independent review: **PASS, no findings**. The reviewer independently checked
the primary currency sources and reproduced the signature vector in PHP and Python,
including all three negative cases.
The public historical vector passed both runtimes and all three raw-body mutation
checks. No application tests were rerun: only documentation changed, and no database
was started. Existing code qualification at `0442721` remains unchanged.
