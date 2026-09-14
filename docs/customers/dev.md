# Customer foundation

2026-09-14. First implementation increment on `codex/customer-commerce-foundation`; deployment is separate. This is a local commerce foundation, not completed provider synchronization or payment processing.

## Domain and existing-account compatibility

`Customer` is a non-authenticatable commerce record. `Patient` remains the existing portal credential/security principal during migration. The nullable unique `customers.portal_account_id` associates one Customer with an existing account. It is not generally fillable and is absent from ordinary create/update DTOs. Customer creation never creates a login, claims a chart, sends email or touches a gateway.

`EnsureCustomerForPortalAccountAction::execute(Patient, ?providerEnvironment)` is an explicit local provisioning operation, not an automatically scheduled backfill. It locks the existing account, returns its existing Customer unchanged or copies local contact/reference fields once. It does not resolve by email, overwrite customer edits, restore a deleted customer or infer a provider environment. Verified create-account enrollment calls provisioning after the existing chart-link action, inside the same transaction. Filament portal-account creation also provisions atomically. Its form has no password input despite a non-null password column; creation now supplies a random undisclosed password through the existing hashed cast, leaving the account unverified and using the existing mailbox reset flow for access. Failures roll back account creation; existing login, token abilities, verification and chart-claim rules are retained. Existing accounts are handled only by the explicit backfill command, never on login or through a model observer. Existing customer history survives physical account deletion; the account FK becomes null. Existing `Patient::orders()` now uses the correct Commerce\Order model, and `Order::patient()` remains separate from `Order::customer()`.

Legacy Customer provider fields remain descriptive snapshots. New `provider_instances` and `customer_provider_links` scope commerce chart references by provider, environment, account type and external provider account/tenant. They do not establish clinical access. No provider records or orders are imported by either migration.

## Data and actions

Migration `2026_09_14_124026_create_customer_commerce_foundation_tables.php` adds customers, encrypted customer_addresses and nullable orders.customer_id. Contact fields (including DOB) and address payloads are encrypted at rest using the application encrypter. DOB uses an ISO date string with an encrypted cast. Customer contact/provider/account fields and address payloads are hidden from generic serialization; explicit authorized UI reads access their properties. Do not use generic model JSON for future public responses.

`CreateCustomerData` validates contact/reference fields for trusted creation. `UpdateCustomerData` contains only contact fields, so all contact writers preserve provider references. Filament only accepts contact fields on creation/edit. `CreateCustomerAction` / `UpdateCustomerAction` use the existing transaction concern. No edit modifies the associated login email/password/2FA or changes chart entitlement.

`UpsertCustomerAddressData` restricts kind to shipping/billing and validates an allowlisted address shape. `SaveCustomerAddressAction` locks the Customer, scopes edited addresses to that owner and serializes default changes per address kind. Country code is normalized uppercase. An address-book change never rewrites historical order shipping/billing snapshots. No delete controls or bulk mutation are introduced.

## Filament and permissions

`Customers/CustomerResource` provides list/create/view/edit, with contact forms, read-only provider references, address manager and a separately authorized link to existing account security. The previous Patient resource is labelled **Portal accounts** in the Customers navigation group; its routes, policies, authentication behavior and signed security history remain compatible.

`CustomerPolicy` requires ViewAny:Customer, View:Customer, Create:Customer and Update:Customer. Address mutations require Update:Customer; account-security access still requires the existing Patient permissions. Existing super_admin gate behavior applies. Assign new permissions through the established authorized role workflow; no broad role/permission seeder was run or added.

Encrypted names/email cannot be searched or sorted directly through SQL. This increment searches customer UUID and PRX patient number only and disables global search. Add a reviewed scoped search index in a later increment rather than pretending encrypted-column LIKE queries work.

## Files

- app/Models/{Customer,CustomerAddress,Patient}.php and app/Models/Commerce/Order.php
- app/Data/Customers/{CreateCustomerData,UpdateCustomerData,UpsertCustomerAddressData}.php
- app/Actions/Customers/{CreateCustomerAction,UpdateCustomerAction,EnsureCustomerForPortalAccountAction,SaveCustomerAddressAction}.php
- app/Policies/CustomerPolicy.php
- app/Filament/Resources/Customers/CustomerResource.php; Pages/{ListCustomers,CreateCustomer,ViewCustomer,EditCustomer}.php; Schemas/{CustomerForm,CustomerInfolist}.php; Tables/CustomersTable.php; RelationManagers/AddressesRelationManager.php
- app/Filament/Resources/Patients/PatientResource.php (navigation/label only)
- database/factories/{CustomerFactory,CustomerAddressFactory}.php; database/migrations/2026_09_14_124026_create_customer_commerce_foundation_tables.php
- tests/Feature/Customers/CustomerFoundationTest.php; tests/Feature/Filament/CustomerAdminTest.php

## Validation

Final integrated isolated SQLite run: **1,512 tests, 5,897 assertions passed**, including existing portal authentication/security and the new ownership, attribution and checkout cases. The first full run lacked the isolated Vite manifest; copying matching installed frontend dependencies into the worktree and building local assets resolved those render failures. `npm run build`, Pint and git diff --check passed. No served asset or dependency files were changed.

A private socket-only MySQL 8.0.46 instance passed **146 tests, 643 assertions** across checkout/cart/pruning, Customer, canonical event/capture and unified-intake selection suites. Four two-connection stale-snapshot checks verified concurrent provisioning winner recovery, concurrently deleted Customer refusal, and claim inclusion of newly committed encounter/order evidence. Ten two-process canonical-event races passed: five identical-payload races returned one shared UUID; five conflicting-payload races yielded one winner and one validation refusal, preserving microsecond occurrence time. Earlier provider-mapping qualification also passed ten same-owner/competing-owner races. All data was disposable and provider traffic faked.

Independent reviews covered ownership/provisioning and checkout through Astra, and canonical events through a separate implementation agent. Findings fixed before commit included stale MySQL snapshots, durable receipts surviving finalization rollback, repeat-purchase cart rotation and expired predecessor token access. These checks qualify the exercised paths, not deployment behavior, external provider contracts or every concurrency failure.

## Release and remaining work

Development/tests use an isolated worktree, independent vendor/autoloader and credentials-free SQLite memory configuration. No live DB snapshot or live migration is needed for that work because it does not touch the served app. Before actual deployment follow the verified snapshot procedure, apply additive schema before the new resource serves requests, provision permissions and qualify against the target DB engine. Never point test runners at the served database. Rolling down removes newly stored customer/address data and must not be used as a production rollback after population without an explicit data-preservation plan.

Still unimplemented: remote customer/order mirrors, vault/transaction ledger, Authorize.net webhook ingestion, refunds/voids, recurring management, shared login, and ACH. No payment readiness or PRX synchronization is implied by the Customer screens. Order detail enforces explicit Customer ownership. Verified claim and API checkout writers now bind trusted local records; historical imports and additional history endpoints still require their own reviewed ownership rules.

## Provisioning and provider-instance mapping

Migration `2026_09_14_150000_create_customer_provider_links.php` adds provider namespaces and scoped Customer links. Apply all branch migrations, including Lead ownership, canonical events and checkout attempts/cart successors, before enabling the new code. Existing rows are never backfilled by a schema migration. All commands below operate only on the local database; run them against a deployed database only through the established snapshot/release procedure.

```sh
# Register a provider tenant/environment namespace; values are deployment configuration.
php artisan customers:provider-instance clinic-sandbox prescribe_rx sandbox provider-tenant-id

# Preview active accounts, then explicitly persist. Repeat --account to select IDs.
php artisan customers:backfill
php artisan customers:backfill --account=123 --apply

# An operator must establish which instance owns each selected historical chart.
php artisan customers:backfill --account=123 --provider-instance=clinic-sandbox
php artisan customers:backfill --account=123 --provider-instance=clinic-sandbox --apply
```

Registration is idempotent for an identical namespace. Ordinary model updates also reject changes to the namespace key, provider, environment or external account ID; lower-level database maintenance must preserve these invariants. Reusing a key with different scope or registering an existing scope under another key fails. This registry contains no credentials, hosts, merchant keys or brand defaults. A provider tenant is not a payment merchant; payment mappings remain separate future work.

`customers:backfill` is dry-run by default: it executes each account in a transaction and rolls it back, including prospective links. It therefore temporarily takes locks and can consume sequence values on some engines; it is not a read-only query. Preview tracks prospective chart reservations across the selected accounts, so duplicate historical chart claims are reported before apply. Concurrent changes after preview can still cause apply conflicts. Apply retries the complete account transaction up to three times on database concurrency errors and commits per account; conflicts are reported by local account ID, return a nonzero exit status and do not discard successful accounts. No contact values or provider identifiers are printed. Re-running is safe: Customer edits are never overwritten, accounts are not merged by email and deleted Customers are not restored or replaced. Soft-deleted accounts are excluded; explicitly selecting one fails before processing.

Provider mapping requires both an explicit instance and explicit account IDs. A conflicting historical environment fails. Unknown historical environment is accepted only under that explicit operator scope selection; neither current integration settings nor the provider name determine historical provenance. Accounts without a chart provision a Customer without a mapping. Existing Customers retain their original reference snapshot; later account/chart changes require explicit reconciliation, not automatic reference overwriting.

`MapCustomerToProviderAction` is for trusted internal callers. It requires a nonempty chart ID, keeps patient ID and display number separate, and locks the Customer then the existing provider-instance primary key. Unique constraints reserve one chart per Customer per instance and one Customer per chart per instance, including a soft-deleted Customer's reservation. Identical references return the existing row. A different owner/chart or supplied optional identifier conflicts; missing optional identifiers are not silently enriched. Different tenants/environments may use identical remote IDs. Opaque identifiers preserve case and accent distinctions; MySQL/MariaDB columns explicitly use `utf8mb4_bin` rather than the installation’s default case-insensitive collation. Patient IDs and display numbers are not assumed globally unique. Customer/instance deletion cannot silently discard a mapping. No ordinary Customer form edits these mappings, and no clinical authorization path reads them.

The isolated MySQL probes exercise same-chart competition and same-owner idempotency under InnoDB; deployment configuration, failover, deadlock fault injection and other load patterns still require release qualification. Provider imports, automatic reconciliation and mapping-management UI remain future work.

## Attribution and conversion reporting requirement

The intended reporting journey is lead/source → Customer → purchase/repeat purchase, with a separate provider-patient/chart and intake relationship for both API unified intake and SDK/embed flows. Preserve first-touch and subsequent attribution history, source/campaign timestamps, checkout path, consent state and a stable local correlation identifier. Measure conversion rates and lead cohorts, cumulative revenue, refunds and net customer value by source; keep clinical enrollment distinct from a paid conversion. These are requirements, not implemented analytics in this increment.

Local/API checkout must retain the lead-to-Customer/order relationship before the provider request. Embed/SDK checkout needs an authenticated server-verifiable correlation contract carried through provider intake and canonical responses/events; public lead UUIDs, browser callbacks and matching email alone must not establish ownership. Existing verified account/lead associations are available as evidence but no historical email-based reconciliation is authorized. Unmatched events remain visible until a trusted correlation exists.

Use durable internal conversion events and canonical payment outcomes for reporting. Provider/gateway webhooks can reconcile those outcomes; they must not directly emit duplicate conversions or initiate new charges. Browser and server marketing deliveries need a shared event identity, consent-aware dispatch, retries/deduplication and a separately approved destination/payload contract. Keep patient/chart/intake identifiers, clinical details and sensitive order contents out of marketing payloads. Decide pixels versus server delivery per integration after that contract is defined. No pixels, marketing dispatch or historical lead-link backfill is implemented here. The verified-claim ownership writer is documented below.

## Customer order detail access

`GET /api/v1/orders/{uuid}` now requires the existing portal Patient session, `patient:*` ability, session lifetime and two-factor enrollment policy. It resolves only orders whose active Customer is explicitly associated with that account; a non-null conflicting legacy `orders.patient_id` also denies access. Missing, unowned, deleted and inaccessible orders return the same 404. Unauthenticated and non-Patient identities return 401; Patient tokens lacking the portal ability return 403. Responses remain no-store and omit address snapshots.

The UUID is an identifier, not authorization. No read assigns order ownership or falls back to account email, chart references or legacy patient_id alone. This intentionally closes anonymous order lookup; unowned checkout orders remain inaccessible through this endpoint until the verified-claim ownership writer establishes their Customer association. The existing clinical `/patient/orders` provider projection is unchanged. See `docs/orders/dev.md` for the endpoint contract.

The [unified attribution contract](../attribution/design.md) expands this requirement across Klaviyo, future Impact, PRX metadata correlation and all future tracking adapters. It preserves the existing non-PHI goal classification and separates approved marketing fields from clinical records.

## Verified claim commerce ownership

Migration `2026_09_14_160000_add_customer_to_leads.php` adds nullable `leads.customer_id` without backfilling rows. The field is hidden from generic Lead serialization and excluded from mass assignment and public Lead input. `Lead::customer()` is the internal association.

`LinkCustomerToClaimedLeadAction::execute(Patient $account, Lead $lead): Customer` is the trusted ownership writer. Account creation and signed-in record claims call it after the existing chart claim and email verification succeed, inside the same token-consumption transaction. It requires fresh persisted `lead.patient_id` matching an active Patient, verified email, verified chart timestamp, and matching chart evidence on an active checkout-created `Encounter.lead_id`. It provisions/reuses the account's Customer through the existing provisioning action, then assigns the Lead and its eligible Orders. Existing claimed Leads can use the same action when later checkout finalization supplies trusted encounter/order evidence.

The action never trusts the Lead's public embed/provider ID fields, never resolves ownership by email, and never creates provider-instance mappings or changes legacy Order/Patient chart entitlement. An existing Lead or Order Customer mismatch, conflicting legacy `order.patient_id`, or an active Order whose Encounter chart differs fails the entire operation. At least one active matching Encounter must exist even if there are no Orders yet. Deleted Customers are not replaced/restored; deleted Orders are untouched. Orders without an active trusted Encounter association remain unowned.

Locks are acquired Lead → Patient → Customer → existing Encounter/Order rows, using fresh authorization reads. All ownership validations precede the ownership writes. Any conflict rolls back provisioning, chart/email verification, ownership changes and claim-token consumption together; it does not burn the mailbox link. Repeating a valid operation preserves established ownership. No historical reconciliation runs automatically.


## Local order history and admin visibility

`GET /api/v1/orders` now lists compact, paginated local order summaries for the existing portal account. A shared controller query enforces the same active Customer association and nonconflicting legacy ownership as detail access. The clinical `/patient/orders` projection is unchanged. See the [order API contract](../orders/dev.md).

The Customer Orders relation requires Customer view plus Order list/view permissions and rechecks access on Livewire requests. It lists only active orders through `Customer::orders()`; there is no ownership assignment action. Its View order link opens the new read-only Order page. That page contains explicit infolist fields for recorded amounts, items, shipments and operational checkout-attempt state, with no header actions or shipment relation-manager mutations. Receipt/result data and request fingerprints are not part of the view. Existing privileged order edit routes remain separate.

The main Orders table identifies Customers using the explicit commerce association, displays local order UUIDs for pending orders without provider numbers, and formats totals using each order's currency. Viewing these records does not verify captured payment, resolve an uncertain checkout or import provider history.

Validation for the local-history/admin increment: the full isolated SQLite suite passed **1,526 tests / 6,143 assertions**. The focused order API and Customer/order admin suites passed **41 tests / 469 assertions** on disposable socket-only MySQL8.0.46. Astra independently approved API and UI code; a separate agent reviewed the guides. Tests cover scoped pagination/counts, data exclusion, denied identities, session/2FA protections, permission revocation after Livewire mount, crafted mutation attempts, currency display and deleted records. Pint and diff checks passed. No served app/database or external provider was used.


## Typed checkout provider namespaces

Provider registration now accepts `--account-type` (default `tenant` for existing callers). API checkout requires an explicit matching `client` or `sales_organization` namespace and freezes it on the attempt. Local finalization for an already claimed Lead maps its canonical receipt chart to the verified Customer under that recorded instance; the claim linker itself does not infer provider mappings. Legacy raw namespaces and historical unbound attempts are not reinterpreted. See [provider binding and reconciliation](../checkout/reconciliation.md).
