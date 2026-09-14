# Customer commerce and portal coordinated release candidate

Status: preparation only. No merge, deployment, served migration, backfill, permission grant, provider mutation, payment or marketing activation is authorized by this document. Normal pushes to the existing admin, portal and Atlas storefront feature branches are authorized separately.

## Candidate and baseline

| Application | Served baseline verified September 14 | Candidate branch | Required ordering |
|---|---|---|---|
| Admin | `9909105` on `main` | `codex/customer-commerce-foundation`, code `d2803ecd66f81d6a1e0a0d5efc79e56350eb2c94` | Schema/settings, admin code and permissions before dependent portal |
| Portal | `116799f` on `main` | `codex/portal-local-commerce-history`, `c583128c7b4e620723b899a98057dbb433962c29` | After owned local history API qualification |
| Storefront | `dde5f4b` on `main` | `codex/storefront-lead-idempotency`, `323cd0b7780595c6bd2d4ffc9ed4420ace876209` | After backend Lead retry schema and two-header contract qualification |

All three baseline commits are ancestors of their feature branches. No integration conflict existed at inspection; recheck immediately before release. The served admin has a pre-existing untracked `docs/portal/patient-portal-runbook-and-docs/` bundle; preserve it. The served portal was clean. Never reset a served checkout to resolve integration. Pin the final approved full commit IDs and artifact digests in the release record; branch names are moving references.

The release adds separate Customer identity/address management, deliberate account provisioning, owned local purchase history, local-first provider checkout with typed binding and retained-receipt repair, internal canonical lead/quiz capture, compatible opt-in Lead submission idempotency, portal finishing and read-only lab-order status (no test results), plus the passive [payment intent/uncertainty foundation](../payments/passive-ledger.md). It does not establish captured revenue, provider history import, no-receipt recovery, or verified embed ownership. The [provider recovery contract](../checkout/provider-recovery-contract.md) records remaining upstream prerequisites. Payment, approval, recurring billing, and marketing delivery activation remain separate releases.

## Before requesting deployment approval

1. Record exact admin/portal/storefront commits, clean feature status and remote commit equality. Recompare served HEAD/status, dependency lockfiles and pending schema/settings migrations. Resolve integration only in isolated checkouts and rerun checks for any resolution.
2. Attach independent Astra review dispositions and test/build results for the final diff. Qualify portal mobile/desktop, keyboard and accessible semantics with synthetic records, including unlinked accounts with eligible local orders, unknown/inaccessible orders, 2FA, expired sessions and backend failure. Record any manual screen-reader or live-provider checks not performed.
3. Build immutable portal/storefront and admin asset artifacts outside the served directories using their lockfiles. Preserve each running frontend `.next`, dependencies and configuration until a qualified replacement is ready. Keep the same portal `SESSION_SECRET`, cookie security and admin token behavior; do not copy served secrets into development. Compare configuration by key presence and approved routing, never print credential values.
4. Select the release window and a drain strategy for checkout/capture requests, queues and scheduled work. List the application-specific worker processes to restart. Do not restart shared Redis, unrelated applications, or the PRX provider. Account for requests already in flight; maintenance alone does not revoke an external call already sent.
5. Complete the backup/restore evidence below. Record selected operator roles, optional account IDs for backfill, typed provider instance/routing configuration, and API checkout enablement decision. A missing selection is not permission for an all-account import or a default provider binding.
6. Present the exact artifacts, schema changes, settings, role grants, release sequence and rollback limits to the operator for deployment approval. Feature-branch push approval does not substitute for this final authorization.

## Backup and restore qualification

The existing deployment guide requires a verified database snapshot before any served database write. A successful dump exit and trailer are necessary but do not prove restore usability. In the authorized release window:

- Take a consistent database snapshot using protected credential configuration, including schema/data, triggers and applicable routines/events. Record database/server version, snapshot time, protected artifact path, byte count and checksum. Check command exit and dump trailer (and compression integrity if compressed). Do not place passwords in shell arguments or logs.
- Restore into a newly provisioned, explicitly guarded disposable database with no network listener or outbound application traffic. Never restore over the served database as a test. Verify representative table counts, FKs, existing account/session/2FA state and encrypted Customer/Lead fields using protected application keys without exposing their values. Check that the final candidate can upgrade the restored schema. A real-data restore requires the operator's release authorization and protected retention controls; current development tests use synthetic data only.
- Preserve the preceding working code/assets and a protected configuration/key recovery artifact. A database dump alone cannot decrypt encrypted fields after key loss, and a `.next` rebuild is not the previous running artifact.
- If restore or upgrade fails, stop before served changes and keep the failure evidence. No restore rehearsal against real records has been run for this candidate; historical snapshot success is not fresh qualification.

## Schema and permissions

Apply the complete candidate migration set in chronological order under the approved drain/maintenance procedure. Inspect the target migration ledger first; never infer pending migrations only from filenames or run `migrate:fresh` against served data.

| Migration | Effect |
|---|---|
| `2026_09_14_124026_create_customer_commerce_foundation_tables` | Customers, encrypted addresses, nullable order ownership |
| `2026_09_14_150000_create_customer_provider_links` | Provider namespaces and scoped Customer/chart links |
| `2026_09_14_160000_add_customer_to_leads` | Nullable explicit Lead ownership |
| `2026_09_14_161000_create_canonical_events_table` | Internal encrypted event history |
| `2026_09_14_162000_create_checkout_attempts_table` | Attempts and cart successor references |
| `2026_09_14_163000_add_account_type_to_provider_instances` | Typed namespace uniqueness; existing rows keep `tenant` |
| `2026_09_14_180000_create_lead_submissions_table` | Owner-secret-bound Lead retry reservations and encrypted frozen responses |
| `2026_09_14_180100_bind_checkout_attempts_and_add_reconciliation_audits` | Nullable historical binding fields and immutable repair audit |
| Settings `2026_09_14_180200_add_checkout_provider_instance_setting` | Null-by-default explicit API checkout provider-instance selection |
| `2026_09_14_203107_create_payment_intents_and_operations_tables` | Passive immutable financial intent/operation identity and encrypted uncertainty; no executor |
| `2026_09_14_223822_create_payment_outcome_observations_table` | Append-only unverified observations; no uncertainty resolution or financial effects |
| `2026_09_14_230000_create_attribution_delivery_previews` | Capture touchpoints, passive delivery identities and immutable policy evaluations; no sending |
| `2026_09_14_231100_create_gateway_account_bindings_table` | Read-verified local gateway account bindings; no automatic mapping or payment execution |
| `2026_09_14_231000_create_email_suppression_observations` | Scoped read-only suppression evidence; no subscriptions or destination sending |

The passive payment tables have no automatic producers or public API. Their presence does not enable collection, vaulting, financial reconciliation or revenue reporting. Add later reviewed candidate migrations to this table before approval. These schema migrations do not import historical accounts or infer historical attempt ownership. Old attempts remain unbound. The idempotency migration can sort before an already-applied later migration on an incremental upgrade; use the migration ledger and apply pending files, never rerun completed migrations. Storefront `323cd0b` adopts the two-header retry contract for quiz/checkout. Verify both headers survive only exact POST `/leads` and that a lost-response retry returns the original Lead before releasing that artifact; old backend code ignores those headers. Other no-header callers remain compatible and still create a new Lead per POST. Spatie settings migrations live under `database/settings` and must be included; checking only `database/migrations` is insufficient.

Customer operators need `ViewAny:Customer` and `View:Customer`; grant `Create:Customer` and/or `Update:Customer` only to chosen authoring roles. Customer order history additionally requires `ViewAny:Order` and `View:Order`. Viewing order history does not require `Update:Order`. Generate missing permission definitions using the repository's Shield procedure and review the resulting diff/grants. Do not broaden every role or assign super-admin to an arbitrary user ID. Permission generation, grants and cache invalidation are served writes and belong to the approved release, not this preparation.

## Admin-first sequence after authorization

1. Drain new commerce writes and affected workers, take and verify the snapshot, then apply the approved schema/settings migrations from the pinned candidate. Preserve in-flight uncertain attempts; do not resubmit provider intake during a release.
2. Activate the matching admin artifact and app-specific caches/workers. Verify migrations/settings loaded and operator role access before reopening dependent traffic. Existing portal login, token abilities, 2FA and session expiry must keep their behavior.
3. Register the explicitly verified provider namespace through `customers:provider-instance` if API checkout is selected. Use `--account-type=client` for explicit Client ID; otherwise `--account-type=sales_organization` for explicit Sales organization ID. Select that exact key in Settings → Integrations. Environment and account ID must match configured routing; when both routing IDs are configured, the client namespace is authoritative and both are frozen. A registry row is not a payment merchant or a credential. See [reconciliation](../checkout/reconciliation.md).
4. Keep API checkout closed until its configuration passes the binding checks. Do not change embed settings to infer API binding, switch payment collector, enable a gateway, or treat existing `tenant` rows as typed registrations automatically.
5. If selected, run `customers:backfill --account=ID` preview for each approved scope, review its result, then explicitly apply the identical selection. Historical chart mapping additionally needs an explicit provider instance and reviewed provenance. Preview rolls back writes but takes locks and can consume sequence values; it is not a read-only inspection. No unconditional all-account backfill is part of this release.
6. Verify the actual protected local `/api/v1/orders` list/detail API with approved test accounts: owned and unlinked-but-eligible accounts succeed; wrong owner/missing/deleted records share 404; expired/wrong-kind credentials fail; 2FA remains enforced; all responses are no-store/noindex. Verify existing clinical routes independently and use only approved read operations against the provider.
7. Activate the prebuilt portal artifact and restart only its service after the admin checks pass. Confirm HTTPS and loopback binding, cookie flags, no-store/noindex, purchase list/detail/pagination and Record navigation when clinical reads fail. Confirm no token or admin host reaches rendered output. Monitor error rates and unresolved attempts without logging response bodies or clinical payloads.

8. Activate the storefront artifact only after backend Lead idempotency is qualified. Exercise synthetic quiz and checkout lost-response retry, same-key validation correction and conflict handling; preserve referral/cart/consent evidence. Missing storage/Web Crypto must refuse submission rather than fall back to an ordinary POST. Keep the existing payment collector and provider routing unchanged. The session record contains retry credentials, not form restoration; context drift can safely conflict after reload. See storefront `tests/README.md`.

## Rollback and stop criteria

Stop release on ownership leakage, authentication/2FA regression, migration failure, missing settings, provider-binding mismatch, broken portal artifacts or duplicate local effects. Preserve evidence and uncertain intents before recovery.

- Portal rollback can restore its preserved preceding artifact first while the additive admin remains deployed. Storefront rollback loses first-party retry headers; preserve the capable artifact or drain lead submissions before restoring an older caller if duplicate prevention is required. Never put the new storefront against an old backend and claim retry safety. Do not delete/rebuild the running `.next` as rollback.
- Once new checkout attempts exist, rolling the admin back to `9909105` would restore the older provider-before-local-write checkout path. It could resubmit an uncertain purchase. Therefore disable/drain affected commerce before any admin code rollback; retain the attempt-aware code or ship a forward fix. Preserve pending/completed attempts, receipts, cart successors and audits.
- Keep additive tables/columns/settings and encryption keys after population. Do not run migration `down` to remove new Customers, events, attempts, audits, Lead retry tombstones or payment intent/operation/observation evidence, attribution touchpoints or delivery evaluations. Losing a retry tombstone can permit a previously used identity to create a new Lead; losing uncertain financial evidence can conceal an unresolved external outcome. Typed namespace rollback can also fail if distinct account types share an external ID; that refusal is intentional.
- A whole-database restore discards every write after the snapshot, including sessions and commerce outcomes. It requires a separate reviewed recovery decision plus reconciliation of intervening writes and external outcomes; it is not routine code rollback. Restoring a database cannot undo a provider submission, charge, shipment or message.

## Evidence record

At preparation: served baselines and ancestry verified read-only, independent worktrees preserved. Prior foundation qualification: 1,545 SQLite tests / 6,280 assertions; 129 focused MySQL tests / 650 assertions; five two-process retained-receipt races. These are historical development results, not a current production restore, deployment or provider-contract test. Append final increment review/build/test results and any synthetic upgrade rehearsal before requesting release approval.

Current reviewed increments: Lead idempotency `b66992e` passed 114 focused MySQL tests / 387 assertions and 15 two-process same-content/conflicting-content/conflicting-owner races, with final eight focused SQLite tests / 61 assertions. Portal finishing `0998fcf` passed seven fixtures, an outbound-blocked build and synthetic Chromium checks at 320/390/1280. Admin lab status passed 31 focused tests / 167 assertions; Astra approved after connection-error and compressed-response fixes. All are isolated development checks; no served snapshot or release has run.

The complete committed-code regression after Lead/labs changes passed **1,560 SQLite tests / 6,391 assertions**. The passive financial increment is qualified separately in its module guide; it was excluded while that test was being authored. This distinction avoids presenting untested work as part of the regression count.

Final passive financial code **`3b74ec2`**: 13 SQLite tests / 129 assertions; 13 focused MySQL tests / 128 assertions before the final microsecond test addition; related payment/routing SQLite 36 / 171; **18/18** two-process MySQL races covering identical/conflicting intent, operation and uncertainty writes with pre-established repeatable-read snapshots. Astra approved the final microsecond delta separately (one focused test / 15 assertions). The private database was stopped. No confirmed payment, vault operation, provider call or destination delivery occurred.

Final portal code **`c583128`**: 11 fixture tests, isolated outbound-blocked production build, synthetic HTTP render checks and Chromium 320/390/1280 qualification all passed, including labs and the prior purchase/Health regressions. Astra approved. The test servers created by these checks were stopped; unrelated existing listeners were preserved. These code IDs plus this release-record-only commit define the prepared candidate; deployment approval and artifact/restore evidence remain outstanding.


## September 14 parallel foundation batch

The financial observation and attribution delivery-preview increments remain internal,
explicit-producer APIs. Neither adds a public route, worker, admin mutation or automatic
backfill. Observed gateway success is unverified evidence; it never changes an Order,
resolves payment uncertainty or creates revenue. Delivery reservations/evaluations do
not authorize sending; missing suppression evidence remains blocked. A future verified
receiver/adapter and destination execution policy require their own reviewed activation.

The committed `CustomerCommerceUpgradeTest` rehearses a populated pre-commerce schema
upgrade on a separate SQLite `:memory:` connection. It preserves legacy Lead/Order
fields, keeps new ownership null, verifies empty new ledgers and null provider-instance
selection, and checks that a second migration run leaves rows and migration batches
unchanged. This includes registered vendor/settings migration paths. It proves a
synthetic additive-upgrade contract, not MySQL production restore compatibility or
historical data quality. No production snapshot or restore has been performed.

Storefront `323cd0b` passed 20 helper/proxy tests, an outbound-blocked production build
and actual Chromium quiz/checkout retry, reload, correction, conflict and storage/header
privacy checks. Independent Astra approved the final code. Its build used a synthetic
loopback backend: it is qualification evidence, not a configured deployable artifact.

Still required for a concrete deployment approval: exact final source IDs and protected
artifact checksums; builds against approved runtime configuration; preservation of prior
running artifacts and keys; an authorized real-data snapshot and disposable restore/upgrade
rehearsal; selected roles/backfill scope/provider routing; release window and affected
worker drain/restart selection. None is implied by a pushed feature branch. Generic
storefront extraction remains after Atlas frontend completion and is not part of this
release batch.


Parallel-batch focused evidence: financial observation plus prior passive ledger suites
passed **20 tests / 204 assertions on both SQLite and disposable MySQL**, with **6/6**
two-process same/conflicting observation-UUID races. Attribution/integration passed
**86 / 223 on MySQL**, new passive attribution **7 / 55 on SQLite**, and **12/12**
two-process scenarios (same touchpoint, same delivery, different destination and stale
snapshot followed by committed consent/configuration changes). The upgrade rehearsal
passed **1 / 40**. Independent Astra ran the new suites together (**15 / 170 SQLite**)
and approved all three tracks after policy-evidence and current-read fixes. No served
records or remote provider/destination accounts were used. The disposable MySQL instance
was stopped after qualification.

Served baselines were rechecked read-only for this batch: admin `9909105`, portal
`116799f`, storefront `dde5f4b`; each remains an ancestor of its candidate. All dependency
lockfiles are unchanged from those baselines. Preserve the admin's pre-existing untracked
portal runbook bundle; portal and storefront served worktrees remain clean.


Final application candidate for this batch: **`d8087b5076172788b6ee633eb896e95e0e53f8ec`**,
including financial observations **`fbfab04`** and attribution previews **`d8087b5`**.
The release-record commit adds only this checklist and the synthetic upgrade test.
The integrated final SQLite regression passed **1,588 tests / 6,690 assertions**.
This supersedes earlier candidate application IDs in the historical evidence paragraphs;
portal `c583128` and storefront `323cd0b` remain unchanged and qualified.


## Gateway mapping and suppression-read continuation

The new internal read adapters must remain explicitly invoked and scoped to configured
accounts. Migrations do not populate account bindings, read live profiles or enable any
sender. Existing payment operation uncertainty and reported observations remain unchanged;
a current transaction read is not a refund balance, ledger projection or execution permit.
Authorizing a future receiver/adapter requires independent account/environment mapping,
current credentials and the documented verification/lineage contract.

Suppression checks supplement local consent; they cannot grant it. A clear remote read
is time- and identity-bound evidence for a passive policy preview, never approval to send.
The preview retains an explicit delivery-disabled reason. No profile upsert, subscribe,
list mutation, marketing event, gateway mutation or provider intake is part of this batch.
The exact reviewed read/freshness limitations and required credential scopes are recorded
in the payment and attribution module guides.

Both new tables are included in the strictly in-memory additive-upgrade rehearsal. Existing
served baselines and portal/storefront candidates remain unchanged; actual artifact and
real-data snapshot/restore qualification still require the separate release process above.

Read-only qualification: the integrated SQLite regression passed **1,609 tests / 7,045
assertions**; final fixture-only additions were then covered by **30 / 463** focused
payment/suppression/preview/upgrade tests. The upgrade rehearsal alone passed **1 / 44**.
Independent review passed after bounded-stream/deadline, transaction-boundary and durable
suppression-ordering fixes. An existing independent reviewer thread was reused after the
agent service refused a new explicitly selected Astra thread due to its thread limit;
this continuation does not claim a newly instantiated Astra review.

Merchant mapping proves the authenticated reporting account at the time of the read;
it does not independently prove ownership of a configured PRX provider mapping. Reported
currency remains current merchant configuration, explicitly **not verified historical
transaction currency**. Signed receiver key encoding, immutable operation correlation,
verified financial projections, vault and execution remain separate contract work.
A newer pending suppression request vetoes older clear evidence, including equal-clock
or late-response races. Clear evidence expires after five minutes and never overrides
current local consent or the delivery-disabled policy.

Suppression MySQL qualification passed eight behavior scenarios / 222 assertions and
**12/12** independent-process races. One test setup raced with the payment migration
filename rename; its focused rerun passed, with no suppression assertion failure.
Gateway database-relevant tests passed **5 / 26** on a separate disposable MySQL schema;
its complete SQLite reporting suite passed **13 / 131**. All HTTP responses were faked.

Gateway account binding also passed **6/6** two-process MySQL races (same and conflicting
remote account identities). The disposable MySQL server was stopped after both tracks
completed. Served checkout baselines were rechecked and remain unchanged.

Final application candidate for this continuation: **`d2803ecd66f81d6a1e0a0d5efc79e56350eb2c94`**,
including suppression **`2e22802`** and gateway reads **`d2803ec`**. The following
release-record commit adds only this evidence and the two-table upgrade assertions.
This candidate supersedes earlier admin application IDs; portal and storefront remain
at the qualified IDs in the table above. Nothing deployed.
