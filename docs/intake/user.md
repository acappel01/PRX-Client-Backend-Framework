# Intake Module — Operator Guide

## Overview

The Intake module connects your storefront to the **prescribe-rx** telehealth platform. When a patient adds a product or package to their cart and proceeds to checkout, the system:

1. Fetches the correct clinical intake questionnaire for the selected products (via the `/api/v1/intake/schema` endpoint).
2. Redirects the patient to a prescribe-rx **embed page** (`/checkout/handoff/{uuid}`) that loads the prescribe-rx checkout iframe.
3. The embed handles all clinical intake questions, payment collection, and encounter creation inside the prescribe-rx platform.
4. prescribe-rx sends signed webhooks back to notify this system when encounters, orders, and shipments change status.

No clinical answers are stored by this application. Clinical data stays entirely within prescribe-rx.

---

## What you need to configure

All prescribe-rx settings live in the admin panel under **Settings → Integrations**.

### Settings → Integrations (prescribe-rx section)

| Field | Description |
|---|---|
| **Enabled** | Toggle on to activate the prescribe-rx integration. When off, the intake schema endpoint returns errors and the handoff page will not load correctly. |
| **Environment** | `sandbox` points to `demo.prescribe-rx.com`. `production` points to `prescribe-rx.com`. Use sandbox for testing before going live. |
| **API Token** | The sales-organization bearer token issued from the prescribe-rx production admin panel. It works against both sandbox and production environments. Stored encrypted. |
| **Sales Org ID** | Optional UUID. If provided, it is sent on the unified-intake payload so the encounter is attributed to your org. Leave blank to let prescribe-rx infer it from the token. |
| **Client ID** | Optional UUID. Same purpose as Sales Org ID — some deployments need both. Leave blank unless prescribe-rx support asks for it. |
| **Embed Code** | The embed code generated in the prescribe-rx admin at `/admin/embed-configs`. This is the auth mechanism for the iframe — without it the checkout embed will not load. Stored encrypted. |
| **Webhook Secret** | The HMAC-SHA256 secret issued alongside your webhook subscription in prescribe-rx. This application uses it to verify the `X-PrescribeRx-Signature` header on inbound webhooks, so that faked events are rejected. Stored encrypted. |

---

## Mapping products and categories to encounter types

The intake system must know **which clinical questionnaire** to show for a given cart. This is controlled by the **Provider Encounter Type ID** field on your catalog items.

An encounter type ID is a UUID assigned by prescribe-rx to a specific type of clinical encounter (e.g. a GLP-1 screening, a testosterone consultation). You can find these IDs in the prescribe-rx admin under Encounter Types, or by asking your prescribe-rx account manager.

### Where to set the encounter type ID

You can set it at two levels:

**Category level (Admin → Catalog → Categories → edit a category)**

Set the **Provider Encounter Type ID** on a category. All products in that category will inherit this encounter type for intake routing unless the product itself has an override.

**Product level (Admin → Catalog → Products → edit a product → Provider tab)**

Products do not currently have a dedicated encounter type ID field in their form — encounter type is resolved from their assigned category. To override a single product, contact a developer to set `provider_encounter_type_id` directly on the product record.

**Package level** — packages also carry a `provider_encounter_type_id` column as a fallback.

### Resolution order

When the frontend requests an intake schema for a cart containing products and packages, the system walks them in this order and uses the first encounter type ID it finds:

1. Product's own `provider_encounter_type_id` (if set directly on the product)
2. Category's `provider_encounter_type_id` (first category attached to the product that has one)
3. Package's own `provider_encounter_type_id` (fallback for package-only carts)
4. If none of the above yield an ID — the API returns a 422 error and the frontend must prompt the operator to configure the mapping.

**Practical advice:** Set the encounter type at the category level. This means any new product added to that category automatically routes to the correct clinical intake without additional configuration.

---

## The checkout handoff flow

When a patient's cart is ready for checkout, the React frontend collects lead information (name, email, phone, DOB, address) and stores it as a **Lead** record. The frontend then redirects the patient to:

```
/checkout/handoff/{lead-uuid}
```

This server-rendered page:
- Builds the prescribe-rx embed payload from the Lead record (prefilling all demographic fields the patient already entered, so they do not have to re-enter them inside the embed).
- Translates the Lead's cart items into prescribe-rx product/package/plan IDs.
- Renders the prescribe-rx embed iframe.

The patient then completes their clinical questionnaire and payment inside the prescribe-rx embed. This application does not touch payment details.

### What gets prefilled in the embed

The embed is given the following fields from the Lead so the patient skips repeating them:

- First name, last name, email, phone
- Date of birth, gender
- Full address (street, city, state, postal code, country)

Steps that are already filled are skipped automatically inside the embed.

---

## Webhook events

prescribe-rx sends signed webhook events to `POST /api/webhooks/prescribe-rx` as encounters, orders and shipments progress. This application uses them to **update** the encounters and orders created at checkout — status, dates and tracking. A webhook never creates an encounter or order and never changes a lead. Setup and troubleshooting: the [inbound webhooks guide](../webhooks/user.md).

You can view encounter and order status in Admin → Commerce.

**Important:** The webhook is the authoritative source of truth. There is also a client-side advisory ping (`/api/internal/checkout/embed-complete`) that fires when the patient appears to finish inside the embed — this is used to mark the lead as handed off immediately, but it is not verified and should not be trusted for business logic.

---

## Stub mode (for testing without a real token)

If you need to test the intake flow before a real prescribe-rx token is issued, set `PRESCRIBE_RX_STUB=true` in your `.env` file. In stub mode, all calls to prescribe-rx return canned fixture responses — no real HTTP requests are made and no credentials are needed. Turn this off before going to production.

---

## Troubleshooting

**Intake schema returns a 422 "No encounter type is mapped" error**

The products in the cart do not have an encounter type ID configured. Go to Admin → Catalog → Categories, find the category for those products, and set the Provider Encounter Type ID field.

**The embed page is blank or shows a loading error**

Check that the **Embed Code** is set correctly in Settings → Integrations. The embed code acts as the auth token for the iframe. Also verify the **Environment** matches where the embed code was generated (sandbox vs. production).

**Webhooks are failing signature verification**

The **Webhook signing secret** in Settings → Integrations does not match the secret on the prescribe-rx webhook subscription. Rotate the secret in prescribe-rx admin and update it here.

**Encounter or order status is not updating**

Confirm that the webhook subscription is active in the prescribe-rx admin, that its URL is exactly `https://yourdomain.com/api/webhooks/prescribe-rx`, and that its failure count is not climbing. Events about records this site does not hold (for example consultations started in the embedded checkout) are kept as *unmatched* rather than applied — see the [inbound webhooks guide](../webhooks/user.md).
