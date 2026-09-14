# Customer commerce and portal coordinated release candidate

Status: preparation only. No merge, deployment, served migration, backfill, permission grant, provider mutation, payment or marketing activation is authorized by this document. Normal pushes to the two existing feature branches are authorized separately.

## Candidate and baseline

| Application | Served baseline verified September 14 | Candidate branch | Required ordering |
|---|---|---|---|
| Admin | `9909105` on `main` | `codex/customer-commerce-foundation` (foundation through `a25c090`, lead idempotency `b66992e`, plus reviewed increments recorded in the handoff) | Schema/settings, admin code and permissions before dependent portal |
| Portal | `116799f` on `main` | `codex/portal-local-commerce-history` (purchases through `e904a9e`, finishing `0998fcf`, plus reviewed labs increment) | After owned local history API qualification |

Both baseline commits are ancestors of their feature branches. No integration conflict existed at inspection; recheck immediately before release. The served admin has a pre-existing untracked `docs/portal/patient-portal-runbook-and-docs/` bundle; preserve it. The served portal was clean. Never reset either served checkout to resolve integration. Pin the final approved full commit IDs and artifact digests in the release record; branch names are moving references.

The release adds separate Customer identity/address management, deliberate account provisioning, owned local purchase history, local-first provider checkout with typed binding and retained-receipt repair, internal canonical lead/quiz capture, compatible opt-in Lead submission idempotency, portal finishing and read-only lab-order status (no test results). It does not establish captured revenue, provider history import, no-receipt recovery, or verified embed ownership. The [provider recovery contract](../checkout/provider-recovery-contract.md) records remaining upstream prerequisites. Payment, approval, recurring billing, and marketing delivery activation remain separate releases.

## Before requesting deployment approval

1. Record exact admin/portal commits, clean feature status and remote commit equality. Recompare served HEAD/status, dependency lockfiles and pending schema/settings migrations. Resolve integration only in isolated checkouts and rerun checks for any resolution.
2. Attach independent Astra review dispositions and test/build results for the final diff. Qualify portal mobile/desktop, keyboard and accessible semantics with synthetic records, including unlinked accounts with eligible local orders, unknown/inaccessible orders, 2FA, expired sessions and backend failure. Record any manual screen-reader or live-provider checks not performed.
3. Build immutable portal and admin asset artifacts outside the served directories using their lockfiles. Preserve the running portal `.next`, dependencies and configuration until a qualified replacement is ready. Keep the same portal `SESSION_SECRET`, cookie security and admin token behavior; do not copy served secrets into development. Compare configuration by key presence and approved routing, never print credential values.
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

Add later reviewed candidate migrations to this table before approval. These schema migrations do not import historical accounts or infer historical attempt ownership. Old attempts remain unbound. The idempotency migration can sort before an already-applied later migration on an incremental upgrade; use the migration ledger and apply pending files, never rerun completed migrations. Browser clients must explicitly adopt the two-header retry contract; older callers remain compatible and still create a new Lead per POST. Spatie settings migrations live under `database/settings` and must be included; checking only `database/migrations` is insufficient.

Customer operators need `ViewAny:Customer` and `View:Customer`; grant `Create:Customer` and/or `Update:Customer` only to chosen authoring roles. Customer order history additionally requires `ViewAny:Order` and `View:Order`. Viewing order history does not require `Update:Order`. Generate missing permission definitions using the repository's Shield procedure and review the resulting diff/grants. Do not broaden every role or assign super-admin to an arbitrary user ID. Permission generation, grants and cache invalidation are served writes and belong to the approved release, not this preparation.

## Admin-first sequence after authorization

1. Drain new commerce writes and affected workers, take and verify the snapshot, then apply the approved schema/settings migrations from the pinned candidate. Preserve in-flight uncertain attempts; do not resubmit provider intake during a release.
2. Activate the matching admin artifact and app-specific caches/workers. Verify migrations/settings loaded and operator role access before reopening dependent traffic. Existing portal login, token abilities, 2FA and session expiry must keep their behavior.
3. Register the explicitly verified provider namespace through `customers:provider-instance` if API checkout is selected. Use `--account-type=client` for explicit Client ID; otherwise `--account-type=sales_organization` for explicit Sales organization ID. Select that exact key in Settings → Integrations. Environment and account ID must match configured routing; when both routing IDs are configured, the client namespace is authoritative and both are frozen. A registry row is not a payment merchant or a credential. See [reconciliation](../checkout/reconciliation.md).
4. Keep API checkout closed until its configuration passes the binding checks. Do not change embed settings to infer API binding, switch payment collector, enable a gateway, or treat existing `tenant` rows as typed registrations automatically.
5. If selected, run `customers:backfill --account=ID` preview for each approved scope, review its result, then explicitly apply the identical selection. Historical chart mapping additionally needs an explicit provider instance and reviewed provenance. Preview rolls back writes but takes locks and can consume sequence values; it is not a read-only inspection. No unconditional all-account backfill is part of this release.
6. Verify the actual protected local `/api/v1/orders` list/detail API with approved test accounts: owned and unlinked-but-eligible accounts succeed; wrong owner/missing/deleted records share 404; expired/wrong-kind credentials fail; 2FA remains enforced; all responses are no-store/noindex. Verify existing clinical routes independently and use only approved read operations against the provider.
7. Activate the prebuilt portal artifact and restart only its service after the admin checks pass. Confirm HTTPS and loopback binding, cookie flags, no-store/noindex, purchase list/detail/pagination and Record navigation when clinical reads fail. Confirm no token or admin host reaches rendered output. Monitor error rates and unresolved attempts without logging response bodies or clinical payloads.

## Rollback and stop criteria

Stop release on ownership leakage, authentication/2FA regression, migration failure, missing settings, provider-binding mismatch, broken portal artifacts or duplicate local effects. Preserve evidence and uncertain intents before recovery.

- Portal rollback can restore its preserved preceding artifact first while the additive admin remains deployed. Do not delete/rebuild the running `.next` as rollback.
- Once new checkout attempts exist, rolling the admin back to `9909105` would restore the older provider-before-local-write checkout path. It could resubmit an uncertain purchase. Therefore disable/drain affected commerce before any admin code rollback; retain the attempt-aware code or ship a forward fix. Preserve pending/completed attempts, receipts, cart successors and audits.
- Keep additive tables/columns/settings and encryption keys after population. Do not run migration `down` to remove new Customers, events, attempts or audits. Typed namespace rollback can also fail if distinct account types share an external ID; that refusal is intentional.
- A whole-database restore discards every write after the snapshot, including sessions and commerce outcomes. It requires a separate reviewed recovery decision plus reconciliation of intervening writes and external outcomes; it is not routine code rollback. Restoring a database cannot undo a provider submission, charge, shipment or message.

## Evidence record

At preparation: served baselines and ancestry verified read-only, independent worktrees preserved. Prior foundation qualification: 1,545 SQLite tests / 6,280 assertions; 129 focused MySQL tests / 650 assertions; five two-process retained-receipt races. These are historical development results, not a current production restore, deployment or provider-contract test. Append final increment review/build/test results and any synthetic upgrade rehearsal before requesting release approval.

Current reviewed increments: Lead idempotency `b66992e` passed 114 focused MySQL tests / 387 assertions and 15 two-process same-content/conflicting-content/conflicting-owner races, with final eight focused SQLite tests / 61 assertions. Portal finishing `0998fcf` passed seven fixtures, an outbound-blocked build and synthetic Chromium checks at 320/390/1280. Admin lab status passed 31 focused tests / 167 assertions; Astra approved after connection-error and compressed-response fixes. All are isolated development checks; no served snapshot or release has run.
