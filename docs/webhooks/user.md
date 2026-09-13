# Inbound webhooks — operator guide

Your clinical provider (prescribe-rx) tells this backend when an encounter, order or shipment
changes, by sending a *webhook*: a signed message to a fixed address on this site. This page
covers connecting it, checking it works, and what to do when an event did not apply.

---

## Connecting prescribe-rx

In the **prescribe-rx admin → Webhooks**, the subscription for this site needs:

| Field | Value |
|---|---|
| URL | `https://<your admin domain>/api/webhooks/prescribe-rx` |
| Events | all (`*`), or at least `encounter.*`, `order.*`, `fulfillment.*` |
| Custom headers | **leave empty** — nothing extra is needed |

prescribe-rx shows a **signing secret** once, when the subscription is created (or when you
rotate it). Paste it into **Settings → Integrations → PrescribeRx embed → Webhook signing secret** in this
admin. Without it every delivery is refused.

A common mistake is `https://<your admin domain>/webhooks` — that address does not exist and
every delivery fails.

## Checking it works

1. In prescribe-rx, send a test event from the subscription.
2. It should report success (HTTP 200). A **401** means the secret in this admin does not match
   the subscription's — rotate the secret in prescribe-rx and paste the new one here.
3. prescribe-rx's **failure count** only returns to 0 after a real event is delivered, not after
   a test. It disables a subscription after 50 failures in a row; re-enable it there once fixed.

## What happens to an event

Every accepted event is recorded before it is acted on. It then ends up as one of:

| Status | Meaning |
|---|---|
| Processed | applied to an encounter, order or shipment here |
| Ignored | a kind of event this site does not act on yet (labs, subscriptions, appointments, tests) |
| Unmatched | valid, but about a record this site does not hold — for example a consultation started through the embedded checkout |
| Failed | something went wrong applying it; ask a developer |

Nothing is lost: unmatched and failed events can be re-applied later by a developer
(`webhooks:replay`). Events are only ever used to **update** records this site already created —
a webhook never creates an encounter or order, and never changes who a lead or patient is.

## What is stored

Only identifiers, statuses, dates, amounts and tracking details. Free text that clinic staff
type into prescribe-rx — cancellation reasons, refill notes, provider names — is discarded when
the event arrives. Settled events are deleted after 90 days.
