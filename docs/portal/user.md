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

## Two-step verification

Patients can protect their account with a code from an authenticator app on their phone (Google
Authenticator, Microsoft Authenticator, 1Password and similar) as well as their password. Choose how
it applies under **Settings → Patient portal → Two-step verification**:

- **Off** — not offered. Patients who already turned it on keep it.
- **Optional** — every patient sees a "Protect your account" invitation on Home and can set it up
  from **Record → Two-step verification**, or skip it for 30 days.
- **Required** — patients without it are sent to set it up on their next screen before they can see
  anything else. Nobody is signed out when you switch this on.

When a patient sets it up they get **eight recovery codes**, shown once. Each signs them in once if
they lose their phone. They can create a new set, switch to a new phone (with a code from the old
app or a recovery code), or turn it off (password and a code; not allowed under Required). The
patient is emailed whenever any of this changes.

| The patient says | What to do |
|---|---|
| "I got a new phone" | If they still have the old phone or a recovery code: Record → Two-step verification → *Switch to a new phone or app*. |
| "I lost my phone and my recovery codes" | Confirm you are talking to the account holder (for example, call the phone number on their order). Then open their patient record and press **Reset two-step verification**. They are signed out everywhere, can sign in with their password, and are emailed. **The admin records that you reset it — it cannot record that you checked who you were talking to.** |
| "It says my code didn't work" | The phone's clock must be set automatically. Codes change every 30 seconds; use the newest. After five wrong codes they must enter their password again. |
| "It says too many attempts" | Ten wrong codes in 15 minutes on one account. Wait 15 minutes. If they did not try that many times, someone else has their password — ask them to reset it. |
| "A 'password accepted, code requested' entry I don't recognise" | Someone has their password but not their phone. Ask them to reset their password now. |

## How long patients stay signed in

A patient is signed out of the portal automatically after **30 minutes without using it**, and
always **12 hours after signing in**, however active. Two minutes before the inactivity limit the
portal asks "Still there?" with a **Stay signed in** button; typing in a form counts as activity. After
an automatic sign-out the sign-in page explains why. Both limits are set under
**Settings → Patient portal → Sessions**. A shorter value applies to patients already signed in; a
longer maximum applies from their next sign-in. If you change them, update your written security
policy to match — auditors check that the two agree.

| The patient says | What to do |
|---|---|
| "It keeps signing me out" | Expected after 30 minutes idle or 12 hours. Tell them about the **Stay signed in** prompt. |

## Sign-in activity and signing a patient out

Every sign-in to a patient account is recorded — with the time, the IP address and the browser —
and so are failed sign-in attempts, sign-outs, password changes, emailed links, and changes made
in the admin (email address, record link, delete, restore).

- **Patients** see their own list on the portal's **Record** page under *Sign-in activity*,
  marked "This device" for the session they are using. Changes made in the admin show as
  "by support".
- **You** see it on the patient's record in the admin, under **Security history**, including
  which staff member made each change. Nothing on that list can be edited or deleted.

| The patient says | What to do |
|---|---|
| "There's a sign-in I don't recognise" | Open the patient's record and press **Sign out everywhere**, then ask them to use **Forgot password** on the sign-in page. Signing out alone does not change the password — whoever signed in could sign in again. |
| "It says a sign-in attempt failed and it wasn't me" | Someone typed their email with a wrong password. Nothing got in. If there are many, ask them to choose a stronger password with **Forgot password**. |
| "I lost my phone" | **Sign out everywhere**, then a password reset. |

**Sign out everywhere** is only shown to staff allowed to edit patients, and it is recorded under
your name.

How long the history is kept is set under **Settings → Patient portal** (two years by default).
Older entries are deleted automatically every night. Failed attempts for an email address with
no account are kept too, but they are not attached to anyone's record.

## Related

- Where email settings live: `docs/settings/user.md` → "Where every email setting lives".
- How it works and why: `docs/portal/dev.md` → "Accounts are created by the link".
- The security history in detail: `docs/portal/dev.md` → "Security history".
