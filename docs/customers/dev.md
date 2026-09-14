# Customer foundation

2026-09-14. First implementation increment on `codex/customer-commerce-foundation`; deployment is separate. This is a local commerce foundation, not completed provider synchronization or payment processing.

## Domain and existing-account compatibility

`Customer` is a non-authenticatable commerce record. `Patient` remains the existing portal credential/security principal during migration. The nullable unique `customers.portal_account_id` associates one Customer with an existing account. It is not generally fillable and is absent from ordinary create/update DTOs. Customer creation never creates a login, claims a chart, sends email or touches a gateway.

`EnsureCustomerForPortalAccountAction::execute(Patient, ?providerEnvironment)` is an explicit local provisioning operation, not an automatically scheduled backfill. It locks the existing account, returns its existing Customer unchanged or copies local contact/reference fields once. It does not resolve by email, overwrite customer edits, restore a deleted customer or infer a provider environment. No caller is wired to account enrollment yet. Existing customer history survives physical account deletion; the account FK becomes null. Existing `Patient::orders()` now uses the correct Commerce\Order model, and `Order::patient()` remains separate from `Order::customer()`.

Provider references are descriptive in this increment. They do not establish clinical access or globally unique provider identity. A provider-instance scoped mapping and verified reconciliation are prerequisites to importing remote records. No PRX customers or orders are imported by this migration.

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

Targeted isolated run: **71 tests, 430 assertions passed** across the Customer foundation/UI and existing PatientSecurityOperator, PatientAuth, CreateAccount and ClaimRecord suites. Pint and git diff --check passed. SQLite verifies schema/behavior here; MySQL lock concurrency and live deployment were not exercised.

## Release and remaining work

Development/tests use an isolated worktree, independent vendor/autoloader and credentials-free SQLite memory configuration. No live DB snapshot or live migration is needed for that work because it does not touch the served app. Before actual deployment follow the verified snapshot procedure, apply additive schema before the new resource serves requests, provision permissions and qualify against the target DB engine. Never point test runners at the served database. Rolling down removes newly stored customer/address data and must not be used as a production rollback after population without an explicit data-preservation plan.

Still unimplemented: automatic account/customer backfill and enrollment wiring, remote customer/order mirrors, protected customer history API, order/item UI, vault/transaction ledger, Authorize.net webhook ingestion, refunds/voids, recurring management, shared login, and ACH. No payment readiness or PRX synchronization is implied by the Customer screens. Subsequent work must enforce order ownership before enriching public order responses.
