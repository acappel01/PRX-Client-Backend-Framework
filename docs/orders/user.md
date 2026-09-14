# Orders — operator guide

The Customer commerce branch adds local order history and a read-only order view. Deployment and migrations are separate; these changes do not enable payment processing or import provider history.

## Finding an order

Open **Commerce → Orders** and search by local order UUID or provider order number. Authorized staff can also open a Customer's Orders relation to see active orders explicitly assigned to that Customer. Customer permission alone does not grant access to orders; the order permissions still apply.

Open the order view to inspect its recorded status, amounts, items, shipment information and local checkout-attempt state where available. The new view has no payment, refund, retry or status-edit controls. Existing privileged edit routes are separate; changing a local record there does not charge, refund or modify a provider order.

An unowned order remains visible to authorized order operators in the main order directory, but does not appear under a Customer until a trusted ownership writer links it. Do not use matching email or an order UUID as evidence to assign ownership.

## Understanding the records

API-driven checkout saves a pending local order and item snapshots before the provider request. A pending order therefore may exist even if the request never completed. Its displayed amount is the recorded order amount, not proof of a captured payment or realized revenue. Keep amounts in their recorded currency.

Where a checkout attempt exists:

| State | Meaning |
|---|---|
| Submitting | Local purchase intent exists; the provider request may still be running or its worker may have stopped. |
| Unknown | A provider request or local finalization failed. The outcome needs reconciliation. |
| Completed | A provider response was recorded and attached to the local order. This does not prove payment capture. |

A received provider receipt can survive a later local finalization failure. The read-only view exposes only operational attempt information, not the encrypted receipt, intake answers, request fingerprints or raw provider response. An order from an older or different checkout path may have no local attempt.

Do not delete/reset an uncertain attempt or submit the same purchase again to resolve it. Reconciliation tooling is still being built. Completed purchases receive a new cart through the normal cart endpoints for a new purchase; original checkout retries return the saved result.

## Items and shipments

Items retain the name, quantity and price recorded for the order. Later catalog price changes do not rewrite those snapshots. Item names are encrypted at rest and are not searchable through SQL.

Shipments show the recorded carrier, tracking reference, status and lifecycle timestamps. A shipment status describes fulfillment; it is separate from payment status. Provider order numbers may remain blank until a matching update is received. Imported provider order history is not supplied by this release.

## Portal history

The local commerce API lists only orders explicitly owned by the signed-in account's active Customer. It requires the existing portal session, token abilities, session lifetime and two-factor policy. Deleted orders, deleted Customers, detached account associations and conflicting legacy owners are excluded. List totals count only that account's eligible local orders.

The existing clinical portal order endpoint remains separate. Adding the local history API does not switch the portal frontend automatically or grant access to a clinical chart.

## Provider updates

The existing signed webhook receiver updates matched local orders; it does not create missing orders. See the [webhook guide](../webhooks/user.md). Unknown checkout contexts, provider-instance reconciliation and financial transaction history require later work. The order screen alone does not establish that a charge, refund or affiliate conversion occurred.
