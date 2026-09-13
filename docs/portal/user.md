# Patient portal accounts — Operator Guide

**Audience:** support and operations staff answering "I can't sign in" or "I can't sign up".

## How a patient gets an account

There is no sign-up form that creates an account. A patient enters **only their email address**
on the portal's **Create your account** page, and we email them a link. The link creates the
account when they choose a password, and their record is connected automatically.

The link is only sent when that address has **an order that has been through checkout** — one
with a consultation behind it. This is on purpose: nobody can create an account for someone
else's email address, and every account is proven to belong to the person who reads that inbox.

**Forgot password** works the same way: the patient enters their email and gets a link to choose
a new password. Setting it signs them out on every device, and they get a second email saying
the password was changed.

The two pages are interchangeable. If someone uses "Create your account" but already has one,
they are sent a password link; if they use "Forgot password" but never set up an account and
have an order, they are sent a create-account link.

## What the patient sees

The screen always says the same thing: *"Check your email. If that address has an order or an
account with us, we have sent it a link."* It never says whether an account or order exists —
otherwise anyone could type a name's email and learn whether that person is a patient. **Support
should not confirm it either** unless the caller has proven who they are.

Links work **once** and expire after **one hour**. Asking again replaces the previous link.

## Common problems

| The patient says | Likely cause | What to do |
|---|---|---|
| "No email arrived" | They used a different address from the one on their order | Ask them to try the address they ordered with. Check spam. |
| "No email arrived" (right address) | Their order has not been through checkout yet, or email is switched off | Check the order has a consultation. Check **Settings → Communication → Send email** is on. |
| "The link says it is invalid or expired" | Used already, older than an hour, or a newer link replaced it | Ask them to request a new one and use the newest email. |
| "It says I've asked too many times" | More than 3 requests in an hour for that address | Wait an hour. |
| "I got a 'password changed' email but didn't change it" | Someone used a reset link from their inbox | Ask them to reset their password again now, and check their email account's security. |
| The page says email can't be sent right now | Email is off, no email integration is set up, or the background queue is stopped | Check **Settings → Communication**, **Automation → Integrations** (exactly one sending email), and ask the server admin to confirm the queue worker (Horizon) is running. |

## Deleted accounts

A patient account deleted in the admin gets **no** emails from either page, and links already
sent stop working. Restore the account first if they should have access again.

## Accounts made before email links

Accounts registered the old way (before 2026-09-13) still sign in normally. If such an account
was registered by someone other than the inbox owner, the real owner can take it over with
**Forgot password**: the reset proves their inbox and signs everyone else out.

## Related

- Where email settings live: `docs/settings/user.md` → "Where every email setting lives".
- How it works and why: `docs/portal/dev.md` → "Accounts are created by the link".
