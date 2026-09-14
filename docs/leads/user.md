# Leads Module — Operator Guide

## Overview

The Leads module captures prospective patients who begin the checkout process on the storefront. A lead is created the moment a visitor submits their contact details and cart selection — before they complete payment or encounter intake.

Leads serve two purposes:

1. **Abandonment recovery** — if a visitor closes the tab before finishing, the frontend can retrieve their lead by UUID and pre-fill the form on their return visit (via a recovery email link, for example).
2. **Audit trail** — every checkout attempt, with its cart snapshot and marketing attribution, is recorded so you can review what products were being considered, where traffic came from, and how far each visitor progressed.

Leads are created exclusively by the storefront. There is no "New Lead" button in the admin — you can only view and edit existing records.

---

## Leads List

Navigate to **Leads** in the admin sidebar to see the full list.

**Columns visible by default:**

| Column | Description |
|---|---|
| Captured | How long ago this lead was created. |
| Status | Color-coded badge: New (gray), Handed off to PRX (yellow), Completed (green), Abandoned (red). |
| Name | First + last name as submitted. Searchable. |
| Email | Email address. Searchable; click the copy icon to copy. |
| Subtotal | Cart subtotal in USD at the time of capture. |
| Checkout path | **Local checkout** (NMI/Authorize.net) or **PRX embed**. |
| SMS / Email | Whether the visitor gave marketing consent for each channel. |
| PRX encounter | UUID of the linked prescribe-rx encounter, if handoff has occurred. |

Several columns (Phone, Checkout path, SMS, Email, UTM source, PRX encounter) can be shown or hidden using the column visibility toggle.

**Filters:**

- **Status** — filter by New, Handed off, Completed, or Abandoned.
- **Checkout path** — filter by Local or PRX embed.
- **Trashed** — show only soft-deleted leads, or all records including deleted.

The list sorts newest first by default.

---

## Dispositions (the lead lifecycle)

A **disposition** is a stage in your funnel. Four ship with the system:

| Disposition | Meaning |
|---|---|
| **New** | Just captured. The visitor has not yet completed checkout. |
| **Handed off to PRX** | The lead was passed to the prescribe-rx embed. The PRX encounter and patient IDs are populated. |
| **Completed** | The full encounter was completed and confirmed in prescribe-rx. |
| **Abandoned** | The visitor did not complete checkout and the lead was marked abandoned. |

Dispositions move forward automatically as checkout progresses. You can also change one by
hand on the edit screen.

### Adding your own

**Leads → Dispositions.** Add as many stages as your funnel needs — "Quiz complete",
"Nurture", "Booked a call". Each has a name, a colour for its badge, and a position.

Three things are worth understanding before you create one:

- **The slug is permanent once leads use it.** The name is the label you see and can be
  changed whenever you like. The *slug* is the stable key stored on every lead and matched
  by automations. Once a single lead sits on it, the field locks. To reorganise, create a
  new disposition and move leads to it.
- **The four built-in dispositions cannot be deleted or re-slugged**, because the system
  writes them during checkout. You can still rename, recolour and reorder them.
- **"Starting disposition"** decides where new leads land. Turning it on for one
  disposition turns it off everywhere else.

A disposition that leads are sitting on cannot be deleted — the delete button is hidden
and the **Leads** count column shows you why. Move those leads first.

> Deleting or renaming a slug that leads reference would leave them pointing at a stage
> that no longer exists, and they would display as a raw slug with no colour. The system
> blocks it rather than letting that happen quietly.

---

## Editing a Lead

Click the edit (pencil) icon on any row to open the lead detail form. Leads are grouped into sections:

### Lead (header fields)

| Field | Description |
|---|---|
| UUID | Read-only internal identifier. Used by the frontend to retrieve this lead. |
| Status | The current stage in the checkout lifecycle. Can be corrected manually. |
| Checkout path | Whether this lead is routed through local checkout or the PRX embed. Set at capture time; shown here for reference. |

### Contact

| Field | Description |
|---|---|
| First name / Last name | As entered by the visitor at intake. Required. |
| Email | Contact email. Required. |
| Phone | Optional phone number. |
| Date of birth | Optional. Required by some encounter types. |

### Address

Street address fields used to pre-fill the PRX embed or local checkout. All optional at capture time.

| Field | Notes |
|---|---|
| Address line 1 / 2 | Street and unit/suite. |
| City | |
| State | 2-letter code, e.g. TX. |
| Postal code | ZIP or postal code. |
| Country | ISO 2-letter code. Defaults to US. |

### Consents

| Field | Description |
|---|---|
| SMS consent | Whether the visitor opted in to SMS marketing. |
| Email consent | Whether the visitor opted in to email marketing. |
| Consent given at | Timestamp when consent was recorded. Populated automatically when either consent toggle is true at capture time. |

> **Note:** These consents are collected locally (on your site) for your own marketing use. Prescribe-rx collects its own separate consents inside its embed for clinical communications.

### Consent audit

Below the lead's tabs is a **Consent audit** panel listing every consent decision ever
recorded for that person: the channel, whether it was granted, **the exact sentence they
were shown**, where it happened, their IP address and device, and who recorded it.

This panel is **read-only and cannot be edited or deleted by anyone**, including you. That
is the point of it — a record you can adjust afterwards is not evidence. A withdrawal
appears as a new entry rather than removing the original.

Two things you will see and should not be alarmed by:

- **Rows marked `backfill` with no wording.** These are consents captured before the audit
  existed. The system knows they consented and when, but the wording they saw was never
  stored, so it says so rather than guessing.
- **Entries where consent was *declined*.** If someone was shown the SMS opt-in and left
  it unticked, that is recorded too. It is the record that answers a later complaint.

> The consent wording comes from your quiz's contact step. **Editing that wording changes
> what future visitors see and agree to — it does not change what past visitors agreed
> to**, because each consent stores its own copy.

### Quiz answers

The **Quiz answers** tab shows everything the visitor told the quiz — health goals,
measurements, and any other question you have added — with your own question wording
beside each answer.

It is read-only on purpose: these are the visitor's own statements about their health.

Adding a question to your quiz makes it appear here automatically; there is nothing to
configure. A question you later retire still shows for leads who answered it, marked
**retired**, because it is still real information about a real person.

### Cart snapshot

Shows what the visitor had in their cart at the moment the lead was captured. This is a snapshot — it does not change if the cart is later modified.

| Field | Description |
|---|---|
| Subtotal | Dollar value of the cart at capture time. |
| Item count | Number of items in the cart (computed, read-only). |

### prescribe-rx handoff

These fields are populated automatically when the checkout flow hands the lead off to prescribe-rx.

| Field | Description |
|---|---|
| PRX encounter ID | UUID of the encounter created in prescribe-rx. |
| PRX patient ID | UUID of the patient record in prescribe-rx. |
| Handed off at | When the handoff occurred. |
| Completed at | When the encounter was marked complete. |

### Marketing attribution

Collapsed by default. Contains UTM parameters and referrer/landing page URLs captured from the visitor's browser at lead-creation time. Also includes the IP address and user-agent string recorded for compliance purposes. These fields are read-only in practice — they are set automatically and should not need manual editing.

### Notes

Internal free-text notes visible only to operators. Not shown to the visitor.

---

## Deleting Leads

Leads use **soft delete**: the Delete action on the edit page moves the record to the trash (it is hidden from the default list view but can be restored). Use **Force delete** to permanently remove a record. Use **Restore** to undo a soft delete.

Bulk soft-delete, force-delete, and restore are also available from the list view via the bulk-action menu.

> Use permanent deletion with caution. Deleted leads cannot be recovered.

---

## What Operators Do Not Configure Here

- **Lead capture fields** are defined by the frontend checkout form and the API. You cannot add or remove fields from the admin.
- **Automated status transitions** (New → Handed off → Completed) happen programmatically as checkout progresses. No manual action is required for normal lead lifecycle.
- **Marketing consent handling** (unsubscribe, suppression lists) is outside this module's scope — you would manage that in your email/SMS platform.

## Repeated form submissions

The API now supports an optional retry credential pair for storefront developers.
When a client adopts it, retrying the same accepted submission returns its original
result and creates no extra Lead, consent record or capture event. It does not
merge people by email or erase separate submissions. Changed submissions require
a new key. Existing clients retain their current behavior until they adopt the
contract in the developer guide; this backend change alone does not remove their
duplicate POSTs. No new operator setting or marketing activation is required.
