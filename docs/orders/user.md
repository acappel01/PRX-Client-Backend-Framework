# Orders Module — Operator Guide

## Overview

Orders are local mirror records of purchases processed through PrescribeRx (or, in future, a local payment gateway). An order is created the moment a customer completes checkout, and updated automatically as PrescribeRx sends webhook events (status changes, shipment tracking, delivery confirmation).

Operators manage orders in **Admin → Commerce → Orders**. Orders are read-only in the admin — all status changes originate from PrescribeRx webhooks.

---

## Order Lifecycle

```
Cart → Lead → Checkout submission → Order created (Pending)
                                          ↓
                              PRX webhook: status = processing
                                          ↓
                              PRX webhook: status = shipped  (+ tracking)
                                          ↓
                              PRX webhook: status = delivered
```

| Status | Meaning |
|---|---|
| **Pending** | Order submitted to PRX; awaiting physician review or fulfillment |
| **Processing** | Physician approved; being prepared for shipment |
| **Shipped** | Dispatched from fulfillment center; tracking number available |
| **Partially Shipped** | Multi-item order with some items shipped, others pending |
| **Delivered** | Confirmed delivery by carrier |
| **Cancelled** | Cancelled before shipment (by patient, physician, or admin) |
| **Refunded** | Payment reversed after delivery |

---

## Order Fields

| Field | Description |
|---|---|
| Order number | PrescribeRx order number (e.g. `RX-123456`). Populated by the first webhook after checkout. |
| Status | Current fulfillment status (see lifecycle above). |
| Subtotal / Total | Dollar amounts snapshotted at checkout time. Tax and shipping added by PRX. |
| Currency | Always USD. |
| Placed at | When the order was submitted to PRX. |
| Shipped / Delivered / Cancelled at | Timestamps set when the corresponding webhook event arrives. |

## Order Items

Each order has one or more line items snapshotted from the cart at checkout:

| Field | Description |
|---|---|
| Name | Product name at checkout time (encrypted at rest). |
| SKU | Provider SKU. |
| Quantity | Number of units. |
| Unit price / Line total | Price snapshotted from the cart. |
| Billing period | For subscription plans: monthly, quarterly, etc. |

## Shipments

A shipment is created per fulfillment center dispatch. Most orders have a single shipment; multi-FC orders can have several.

| Field | Description |
|---|---|
| Carrier | Shipping carrier (USPS, UPS, FedEx, DHL). |
| Tracking number | Carrier tracking number — click the tracking URL to open the carrier page. |
| Status | Pending → Shipped → In Transit → Delivered (or Exception). |
| Exception reason | Set if the carrier reports a delivery problem. |

---

## Searching and Filtering

The Orders table supports:
- **Search** by order UUID or PRX order number
- **Filter** by status
- **Filter** by date range (placed_at)
- **Soft-delete** — deleted orders are hidden by default; use the trashed filter to view them

---

## Webhooks

Orders are updated automatically when PrescribeRx sends events. No manual action is needed. Webhooks only update orders this site created at checkout — they never create one. Re-delivering an event is safe.

Webhook endpoint: `POST /api/webhooks/prescribe-rx`. Connecting it and checking it works: see the [inbound webhooks guide](../webhooks/user.md).

---

## Notes for Operators

- **Addresses are not shown in admin** — shipping and billing addresses are encrypted and only accessible to authorized staff with direct database access.
- **Order items are immutable snapshots** — editing catalog prices after checkout does not affect existing orders.
- **The PRX order number is blank briefly after checkout** — it is backfilled when the first webhook arrives (typically within seconds). If it never appears, the webhook may not have fired; check the PRX admin.
