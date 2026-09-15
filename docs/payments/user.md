# Payments Module — Operator Guide

## Overview

The Payments module lets you configure one or more **Merchant Accounts** — connections to payment gateways that the platform uses to process customer transactions. All credentials are stored encrypted in the database and are never exposed in code or configuration files.

Supported gateways:

| Gateway | Use case |
|---|---|
| **NMI** | Direct Post / Collect.js card tokenization |
| **Authorize.Net** | Accept.js tokenization, CIM customer vault |
| **Stripe** | Stripe Elements / PaymentIntents |
| **Square** | Square Web Payments SDK |

---

## Admin Panel: Merchant Accounts

Navigate to **Payments → Merchant Accounts** in the admin sidebar.

### List view

The list shows all configured accounts with at-a-glance status:

| Column | Meaning |
|---|---|
| Name | Your internal label for the account |
| Gateway | Which payment provider (NMI, Authorize.Net, Stripe, Square) |
| Environment | Sandbox (testing) or Production (live) |
| Creds | Key icon = credentials are present and valid; warning triangle = required credential fields are empty |
| Active | Whether this account is eligible for payment routing |
| Default | Whether this is the fallback account when no specific account is selected |
| Monthly limit | Optional cap on monthly processing volume |
| Volume used | Running total for the current month |

You can filter by gateway and environment. Deleted (soft-deleted) accounts are hidden by default; use the **Trashed** filter to show them.

---

### Creating or editing an account

#### Account section

| Field | Description |
|---|---|
| Name | Internal label, e.g. "NMI Primary" or "Stripe Sandbox". Not shown to customers. |
| Gateway Provider | NMI, Authorize.Net, Stripe, or Square. Selecting a gateway reveals the matching credential fields below. |
| Environment | **Sandbox** = test mode. **Production** = live charges. Always start with Sandbox. |

#### Credential sections

Only the section for the selected gateway is shown.

**NMI credentials**

| Field | Description |
|---|---|
| Security Key | Server-side key for Direct Post API. Found in your NMI control panel under Settings → Security Keys. Stored encrypted. |
| Public Key (Collect.js) | Client-side key for browser-based card tokenization via Collect.js. Safe to expose to the browser. Not encrypted. |

**Authorize.Net credentials**

| Field | Description |
|---|---|
| API Login ID | From your Authorize.Net merchant portal. Stored encrypted. |
| Transaction Key | From your Authorize.Net merchant portal. Rotate this key if it is ever compromised. Stored encrypted. |
| Public Client Key (Accept.js) | Used for browser-based tokenization via Accept.js. Not encrypted — safe to expose client-side. |
| Webhook Signature Key | HMAC key for verifying inbound webhook signatures from Authorize.Net. Stored encrypted. |

**Stripe credentials**

| Field | Description |
|---|---|
| Secret Key | Starts with `sk_live_` (production) or `sk_test_` (sandbox). Found in the Stripe Dashboard under Developers → API keys. Stored encrypted. |
| Publishable Key | Starts with `pk_live_` or `pk_test_`. Safe to expose in the browser for Stripe Elements. Not encrypted. |
| Webhook Signing Secret | Starts with `whsec_`. Found in Stripe Dashboard → Webhooks. Used to verify inbound webhook payloads. Stored encrypted. |

**Square credentials**

| Field | Description |
|---|---|
| Access Token | Server-side bearer token for Square API calls. Production or sandbox. Stored encrypted. |
| Application ID | Client-side ID for the Square Web Payments SDK. Not encrypted. |
| Location ID | Your Square location identifier, required on all Payments and Refunds API calls. Not encrypted. |
| Webhook Signature Key | HMAC-SHA256 key for verifying inbound Square webhook payloads. Stored encrypted. |

#### Status sidebar

| Toggle | Description |
|---|---|
| Active | When off, this account is skipped entirely during payment routing. Use this to take an account offline without deleting it. |
| Default | Marks this account as the system-wide fallback. Only one account should be set as default at a time. |

#### Capabilities sidebar

| Toggle | Description |
|---|---|
| Allows Recurring Payments | Enable for accounts that will process subscription or recurring billing. |
| Allows Rx Processing | Enable for accounts that process prescription-related transactions. |
| Allows Card Present | Enable for accounts configured for in-person / card-reader transactions. |
| Allows Card Not Present | Enable for accounts handling online card-not-present (CNP) transactions. |
| Supports Public Checkout | Enable for accounts that process charges initiated from the public-facing checkout flow. |
| Enable CIM | Authorize.Net only. Enables the Customer Information Manager for storing payment methods on file. |

#### Volume & routing section (collapsed by default)

| Field | Description |
|---|---|
| Transaction Weight | Relative weight for load-balancing across multiple active accounts. Higher weight = more traffic directed to this account. Default is 1. |
| Monthly Volume Limit | Optional dollar cap on monthly processing. Leave blank for unlimited. |
| Auto-disable when monthly limit reached | If enabled, the account is automatically deactivated when it hits its monthly limit. |
| Custom Gateway Endpoint URL | Overrides the default API endpoint for this gateway. Leave blank unless you are using an NMI sandbox proxy, an on-premise deployment, or a non-standard environment. |

#### Surcharge section (collapsed by default)

Use this to attach a surcharge to all transactions routed through this account.

| Field | Description |
|---|---|
| Rate (%) | Percentage surcharge, e.g. `6.5` = 6.5%. |
| Flat fee | Fixed dollar amount added per transaction, e.g. `0.30`. |
| Pass through to sales org | When enabled, the surcharge is billed back to the sales org at settlement. |

---

## Workflows

### Adding your first live account

1. Create the account in your payment gateway's merchant portal (NMI, Authorize.Net, Stripe, or Square).
2. In admin, go to **Payments → Merchant Accounts → New**.
3. Set a clear **Name** (e.g. "Stripe Production").
4. Select the **Gateway Provider** and set **Environment** to **Production**.
5. Enter credentials in the matching credentials section.
6. Toggle **Active** on and **Default** on (if this will be the primary account).
7. Save. The **Creds** icon in the list view will show a key icon if all required fields are present.

### Testing with sandbox first

Always create a sandbox account first:
1. Get sandbox/test credentials from your gateway portal.
2. Create a Merchant Account with **Environment = Sandbox**.
3. Run test transactions through the checkout flow.
4. Once satisfied, create a separate **Production** account with live credentials.
5. Set the production account as **Default** and deactivate the sandbox account.

### Running multiple accounts (load balancing)

You can run more than one active account on the same gateway (or across gateways). The platform routes transactions to active accounts based on **Transaction Weight**. For example:

- Account A: weight 3 → receives ~75% of transactions
- Account B: weight 1 → receives ~25% of transactions

Set **Default** on the account that should be used when no routing logic selects a specific account.

### Monthly volume caps

If a gateway has a monthly processing limit in its merchant agreement:

1. Enter the dollar cap in **Monthly Volume Limit**.
2. Toggle **Auto-disable when monthly limit reached** on.
3. The platform will track volume in **Monthly Volume Used**. When the limit is hit, the account is deactivated automatically.

> Note: `Monthly Volume Used` is not automatically reset at the start of each month in the current version. A developer will need to reset it or a scheduled task must be configured.

---

## Gotchas

- **Sandbox vs. Production environment**: switching an account from Sandbox to Production does not change the credentials — you must also update the credential fields to production keys.
- **One default account**: only one account should have **Default** toggled on. If multiple accounts are marked default, the system uses the first active one it finds.
- **CIM (Authorize.Net)**: Enable CIM only if your Authorize.Net account has the Customer Information Manager feature enabled in the merchant portal. Enabling the toggle here without CIM active on the Authorize.Net side will cause vault operations to fail.
- **NMI public key vs. security key**: the Security Key is server-side only. The Public Key is what you embed in your frontend for Collect.js tokenization. Never expose the Security Key to the browser.


## Payment intent and uncertainty foundation

The internal commerce ledger can now retain planned payment operations and
unresolved outcomes with frozen account, amount and executor identities. It does
not add payment buttons, take payments, import historical gateway transactions or
make stored cards usable. A prepared or uncertain operation is not confirmed
revenue, and an order total or accepted intake is not evidence of collection.
Gateway verification, reconciliation and instrument-readiness contracts remain
required before enabling execution. No new operator configuration is needed for
this passive development increment.


## Follow-up outcome evidence

Internal payment observations can now retain later reported outcomes alongside an
uncertain operation. These records are unverified evidence only. There is no new
operator screen or action to charge, retry, resolve uncertainty, refund, save a
card, or mark an order paid. A reported capture/settlement/refund does not establish
that money moved. Gateway account qualification and authenticated current-state
reconciliation remain prerequisites; do not treat a report as permission to retry.


## Read-only gateway account qualification foundation

Developers can now qualify an explicitly selected Authorize.Net account mapping
and read bounded transaction details through an internal service. There is no new
operator button, automatic account scan, payment action or webhook receiver.
Deployment configuration has not been changed and no real account was queried.

Before a separately authorized qualification, supply the local merchant row,
environment, real gateway account ID, currency and any explicit external provider
row mapping. A PRX merchant UUID identifies its database row; it is not the
Authorize.Net gateway ID. Duplicate local rows can share the same verified remote
identity, but each credential set must independently match it. Changing mapped
credentials or configuration later blocks reads until a reviewed rebinding policy
exists. Do not edit immutable binding records to bypass that protection.

A current transaction read is not a paid order, a resolved uncertain payment, or
permission to retry. Current account currency does not independently prove an old
transaction's currency. Signed notification intake, historical currency authority,
local operation ownership and settlement/refund accounting remain prerequisites
for activation. No payment collection or marketing sending is enabled by this
increment.

## Notification and payment-reference preparation

An inactive internal inbox and operation-reference registry are available for future integrations. There is no new receiving URL, background processing or operator activation control. Reference matches are not confirmed payments, and a signed notification does not mark an order paid. Account/key changes require an explicit continuity plan. See [implementation and activation boundaries](inactive-receiver.md).
