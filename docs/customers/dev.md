# Customer foundation

2026-09-14. First implementation increment on `codex/customer-commerce-foundation`; deployment is separate. This is a local commerce foundation, not completed provider synchronization or payment processing.

## Domain and existing-account compatibility

`Customer` is a non-authenticatable commerce record. `Patient` remains the existing portal credential/security principal during migration. The nullable unique `customers.portal_account_id` associates one Customer with an existing account. It is not generally fillable and is absent from ordinary create/update DTOs. Customer creation never creates a login, claims a chart, sends email or touches a gateway.

`EnsureCustomerForPortalAccountAction::execute(Patient, ?providerEnvironment)` is an explicit local provisioning operation, not an automatically scheduled backfill. It locks the existing account, returns its existing Customer unchanged or copies local contact/reference fields once. It does not resolve by email, overwrite customer edits, restore a deleted customer or infer a provider environment. Verified create-account enrollment calls provisioning after the existing chart-link action, inside the same transaction. Filament portal-account creation also provisions atomically. Its form has no password input despite a non-null password column; creation now supplies a random undisclosed password through the existing hashed cast, leaving the account unverified and using the existing mailbox reset flow for access. Failures roll back account creation; existing login, token abilities, verification and chart-claim rules are retained. Existing accounts are handled only by the explicit backfill command, never on login or through a model observer. Existing customer history survives physical account deletion; the account FK becomes null. Existing `Patient::orders()` now uses the correct Commerce\Order model, and `Order::patient()` remains separate from `Order::customer()`.

Legacy Customer provider fields remain descriptive snapshots. New `provider_instances` and `customer_provider_links` scope commerce chart references by provider, environment and external provider account/tenant. They do not establish clinical access. No provider records or orders are imported by either migration.

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

Combined isolated SQLite run: **166 tests, 1,053 assertions passed** across Customer foundation/provisioning/UI, order detail/ownership, PatientSecurityOperator, PatientAuth, CreateAccount, ClaimRecord, TwoFactor, SessionLifetime, TrustedDevices and PasswordReset suites. Pint and git diff --check passed. A separate socket-only MySQL 8.0.46 instance passed **60 tests, 363 assertions** across Customer, enrollment, claim and UI suites, including case/accent identity distinctions. Two-process mapping probes passed five competing-owner races (one mapping and one conflict each) and five same-owner races (both return the same mapping). All database data was disposable; no served database or provider was accessed. These tests qualify the exercised paths, not deployment behavior or every concurrency scenario.

## Release and remaining work

Development/tests use an isolated worktree, independent vendor/autoloader and credentials-free SQLite memory configuration. No live DB snapshot or live migration is needed for that work because it does not touch the served app. Before actual deployment follow the verified snapshot procedure, apply additive schema before the new resource serves requests, provision permissions and qualify against the target DB engine. Never point test runners at the served database. Rolling down removes newly stored customer/address data and must not be used as a production rollback after population without an explicit data-preservation plan.

Still unimplemented: remote customer/order mirrors, Customer order history/list API, order/item UI, vault/transaction ledger, Authorize.net webhook ingestion, refunds/voids, recurring management, shared login, and ACH. No payment readiness or PRX synchronization is implied by the Customer screens. Order detail now enforces explicit Customer ownership; trusted ownership writers must be implemented before importing or exposing additional order history.

## Provisioning and provider-instance mapping

Migration `2026_09_14_150000_create_customer_provider_links.php` adds provider namespaces and scoped Customer links. Apply both additive Customer migrations before enabling enrollment/resource code. Existing rows are never backfilled by a schema migration. All commands below operate only on the local database; run them against a deployed database only through the established snapshot/release procedure.

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

Use durable internal conversion events and canonical payment outcomes for reporting. Provider/gateway webhooks can reconcile those outcomes; they must not directly emit duplicate conversions or initiate new charges. Browser and server marketing deliveries need a shared event identity, consent-aware dispatch, retries/deduplication and a separately approved destination/payload contract. Keep patient/chart/intake identifiers, clinical details and sensitive order contents out of marketing payloads. Decide pixels versus server delivery per integration after that contract is defined. No pixels, marketing dispatch, lead-link backfill or new lead ownership writer is implemented here.

## Customer order detail access

`GET /api/v1/orders/{uuid}` now requires the existing portal Patient session, `patient:*` ability, session lifetime and two-factor enrollment policy. It resolves only orders whose active Customer is explicitly associated with that account; a non-null conflicting legacy `orders.patient_id` also denies access. Missing, unowned, deleted and inaccessible orders return the same 404. Unauthenticated and non-Patient identities return 401; Patient tokens lacking the portal ability return 403. Responses remain no-store and omit address snapshots.

The UUID is an identifier, not authorization. No read assigns order ownership or falls back to account email, chart references or legacy patient_id alone. This intentionally closes anonymous order lookup; current unowned checkout orders remain inaccessible through this endpoint until a trusted ownership writer is implemented. The existing clinical `/patient/orders` provider projection is unchanged. See `docs/orders/dev.md` for the endpoint contract.
