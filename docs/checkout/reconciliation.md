# Provider binding and local checkout reconciliation

Implemented in the isolated Customer commerce branch. Apply the branch's schema and settings migrations before serving this code. No live migration, provider call, historical binding or payment operation is part of development qualification.

## Explicit provider namespace

Provider instances now distinguish `provider`, `environment`, `account_type` and the case-sensitive external account ID. Namespace keys and all scope fields are immutable. Existing registrations keep `account_type=tenant`; they are not reinterpreted as clients or sales organizations. The registration command still defaults to `tenant` for existing generic/backfill callers. Other providers may register their own stable account-type slugs.

For API checkout, register the actual explicit routing scope, for example:

```sh
php artisan customers:provider-instance checkout-client prescribe_rx sandbox configured-client-id --account-type=client
# If the request has no explicit Client ID, use its explicit Sales organization ID instead:
php artisan customers:provider-instance checkout-organization prescribe_rx sandbox configured-sales-org-id --account-type=sales_organization
```

Choose that key under **Settings → Integrations → API checkout provider instance**. The selected record must match `prescribe_rx`, the exact API environment and the explicit Client ID; if Client ID is absent, it must match the explicit Sales organization ID. When both IDs are supplied, the client namespace is authoritative and both routing IDs are frozen. Token-derived tenant defaults are not sufficient evidence for new API checkout. The selection does not affect the existing embed configuration.

New attempts record an immutable provider-instance FK, tenant kind, both routing IDs, environment and keyed order snapshot fingerprint before submission. A missing/mismatched binding refuses a new checkout before saving purchase intent or contacting the provider. Later configuration changes never rewrite an existing attempt. Legacy unbound attempts remain unbound and cannot use local reconciliation; historical provenance needs a separately reviewed migration/reconciliation, not the current settings.

Account types with identical external IDs can coexist. Migration rollback checks for namespaces that would collapse before any DDL and restores the old uniqueness constraint before removing the typed index/column. Do not use schema rollback to discard populated attempts, audit records or provider identity history.

## Inspect, preview, apply

The read-only Order view shows the checkout context UUID, recorded provider-instance key, state, receipt availability and receipt-received time. It does not display the encrypted receipt, routing IDs, request fingerprints or raw response.

Use the durable checkout UUID and its recorded instance key:

```sh
# Default preview performs the same local checks and rolls back all writes.
php artisan checkout:reconcile attempt-uuid --provider-instance=checkout-client

# Explicit apply needs a bounded operator reason.
php artisan checkout:reconcile attempt-uuid --provider-instance=checkout-client --apply --reason='Reviewed local finalization failure; apply retained receipt'
```

These are trusted local administrative commands, not public endpoints. Preview temporarily takes locks and may consume sequence values; it is not a read-only query. Apply requires a nonblank reason of at most 1,000 characters. Console output contains no provider identifiers, receipt content or raw exception text.

The shared finalizer reads the stored minimal encrypted receipt and the immutable attempt binding. The supplied key must identify that exact recorded instance; current integration settings are not consulted. It requires a pending, unchanged order snapshot, valid Lead/Cart pairing and compatible ownership. It creates a trusted local Encounter, attaches the existing Order and finalizes the saved result atomically. Claimed Leads pass the existing verified claim linker and reserve the canonical chart under the recorded Customer/provider-instance namespace. A conflicting mapping refuses the whole transaction. Anonymous checkout does not gain a Customer or clinical entitlement.

Cart edits made since submission survive. Receipt-received time is retained for the provider handoff milestone instead of substituting the repair time. Completed replays return the existing result without creating another Encounter or reconciliation audit.

An applied repair appends an immutable local audit with attempt, instance, before/after state, encrypted reason, source and time. Reconciliation suppresses model automation so it does not replay lead workflows, CRM/SMS sends, outbound webhooks or provider actions. Ordinary checkout keeps its existing event behavior. Preview rolls back both business writes and the prospective audit.

## Outcomes that remain unresolved

No provider receipt means there is no verified local result to finalize. Missing bindings, changed snapshots, ownership conflicts and incompatible states also refuse reconciliation. The original attempt and receipt remain available for review; do not delete/reset them or resubmit intake to resolve uncertainty.

This command never queries or writes PRX, retries intake, changes payment state, issues a refund or activates marketing. Provider reads by verified context, signed identifier-only webhook ingestion, verified embed/session correlation and financial reconciliation remain separate work. A completed local checkout or a linked chart is not evidence of captured payment.

## Validation

The full isolated SQLite suite passed **1,545 tests / 6,280 assertions**. Focused checkout, Customer, namespace, settings and order-view suites passed **129 tests / 650 assertions** on disposable socket-only MySQL 8.0.46. Five two-process reconciliation races each returned two successful commands while persisting exactly one Encounter, audit and completed attempt, preserving receipt time and order/cart state. Tests exercise preview rollback, apply replay, settings changes, missing/mismatched/untyped bindings, missing receipt, ownership and chart mapping conflicts, changed order snapshots, retained cart edits, audit immutability and workflow suppression. Astra independently approved registry, binding, finalization, UI and documentation; Pint and diff checks passed. No served database or provider was accessed. CLI concurrency failures outside these cases fail with rollback; an operator may need to repeat the local command after resolving the conflict, never resubmit intake.
