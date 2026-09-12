# Integrations — connecting your other tools

**Automation → Integrations.** This is where you connect this site to the services you already
use — your CRM, your email platform, your SMS provider — so your workflows can send people and
their details to them.

Nothing here does anything on its own. An integration is a *destination*; your workflows decide
what goes to it and when.

## Adding one

1. **Add integration**, and give it a name you will recognise later. If you have two accounts
   with the same service, name them apart — "Klaviyo — Marketing" and "Klaviyo — Ops" — because
   this is the name you will pick from when building a workflow.
2. Choose the **service**. This installation ships with:

   | | What it is for | What you will need |
   |---|---|---|
   | **This site's own email** | One-to-one email, no extra account | nothing |
   | **Klaviyo** | Contacts, lists and events | your private API key (starts `pk_`) |
   | **GoHighLevel** | Contacts, tags and starting workflows | your API token and Location ID |
   | **Twilio** | Text messages | your Account SID and auth token |

   If yours is not listed it needs to be added in code — ask your developer; the system is
   built to take new ones without touching anything else.

   **Klaviyo and GoHighLevel do not do the same things**, and the workflow builder will only
   offer you what each can actually do. Klaviyo can *record an event* that one of your flows
   triggers on, but cannot drop somebody straight into a flow. GoHighLevel is the opposite: it
   can *start a workflow* directly, but has no events at all. Neither can start someone in the
   middle of a sequence, so pick the sequence rather than the step.
3. Paste your **credentials**. They are encrypted, and nobody can read them back out of the
   admin.
4. Tick **what you use it for**. This is the important part — see below.
5. Save, then use **Test connection** to confirm the credentials actually work. Do this now
   rather than finding out from a workflow that quietly failed next week.

> **Please test each one before you rely on it.** The Klaviyo, GoHighLevel and Twilio
> connectors were written from those companies' published documentation, but have not yet been
> run against a real account here. **Test connection** is the fastest way to find out, and if
> something comes back wrong the message you see is the vendor's own — pass it on rather than
> working around it.

## "What you use it for" is not a formality

Tick only what your account with that provider is genuinely authorised to do.

| | |
|---|---|
| **SMS** | Text messages |
| **Transactional email** | One-to-one messages — a confirmation, someone's plan, a password reset |
| **Marketing email** | Campaigns and nurture sequences |
| **CRM / contact sync** | Pushing people and their details to a CRM or marketing platform |

Two reasons this matters:

- **Your workflow steps come from these ticks.** "Send an SMS" does not appear as an option in
  the workflow builder at all until some integration is enabled for SMS. If a step you expect
  is missing, this is almost always why.
- **One account is often not cleared for everything.** A phone provider may be approved for
  texts but not calls; an email provider for receipts but not campaigns. Ticking something you
  are not authorised for is how an account gets suspended.

You can only tick what the service is actually capable of, so the list changes with the
service you chose.

Switching an integration **off** removes it from every workflow step that offers it. Workflows
already using it will fail loudly and say so in their run log — which is deliberate, so a
funnel that stopped working tells you rather than going quiet.

## Health data — read this before ticking it

**Atlas collects health information.** Measurements, medications, anything flagged as a
condition, sex, age — and the recommendations that come out of them. Some services are
contractually allowed to receive that and some are explicitly not; several major marketing
platforms forbid it in their terms and will not sign an agreement covering it.

So every integration starts as **not permitted for health data**, and any field marked as
health data is refused or blanked before it reaches that destination.

**A goal is not health data**, and that is a deliberate decision rather than an oversight.
"More energy" or "lose weight" is what somebody *wants*, not a condition, a diagnosis or a
treatment — so goals go to your marketing platform like any other personal detail, which is
what makes segmenting a campaign on them possible at all. What comes *out* of the quiz is
different: a recommended protocol name, or a list called "TRT interest", discloses health
status even though the goal behind it did not. Mind the names you send, not only the values.

To change that, open the integration and use **Permit health data**. You will be asked to write
down what you are relying on — "BAA signed with X on this date, reference Y". That note, your
name and the date are recorded permanently and **cannot be edited or deleted afterwards**. A
change of mind is a new entry, not a correction.

**This records your declaration; it is not a check we can perform.** Nothing in this system can
confirm an agreement exists. That is exactly why your name is on it.

**Withdrawing works the same way** and takes effect immediately — workflows stop sending health
fields to that destination on their very next run, without anyone editing them.

Two things worth knowing:

- **A list or tag name can disclose health information by itself.** A list called "TRT
  interest" tells a destination as much as an answer would. So can a product or protocol name.
  Think about the names you send, not only the values.
- **Redaction is often enough.** When you map a field you can choose *Send "[redacted]"*, which
  tells the destination the field was answered without sending what it said. For many
  campaigns, "completed the quiz" is all the platform actually needs.

## Sending fields to a CRM

In a workflow, add the step **Send to an integration**, choose the destination, then choose what
to do. The options depend on what that provider can do — they genuinely differ, and one that
cannot record events will not offer it:

- **Create or update the person** — and optionally add them to a list or tag.
- **Record an event** — "completed the quiz", for platforms that work that way.
- **Start an automation** — drop them into a sequence, for platforms that allow it.

Then map the fields. Each row is *from* a field here, *to* whatever that service calls it.

Fields carrying health data are marked **⚕ health data** in the list, and personal ones with a
**·**. For each one you choose what happens when the destination is not permitted for health
data: refuse to send, send "[redacted]", or send it anyway. **Refuse is the default**, so a
health field you map without thinking about it is blocked rather than sent.

Two limits worth knowing, because they are not obvious:

- **Only fields marked ⚕ are gated.** Fields marked **·** personal — a name, an email address —
  go wherever you map them. That is deliberate, but it means "personal" is not a partial block.
- **The list or tag name you type is not checked at all.** A list called "TRT interest"
  discloses someone's health status just as plainly as an answer would, and so does a protocol
  or product name. Nothing can catch that for you; choose neutral names.

Every question you write counts as health data unless you say otherwise — you can change that
per question under **Quizzes → the question → Sensitivity**. Leave it on *Automatic* unless you
are sure a question is not clinical. The goals question is the exception and is already treated
as personal rather than health data, so you can segment campaigns on it.

## Lists, and who actually ends up on one

**Adding somebody to a marketing list is not the same as subscribing them**, and getting that
wrong is silent in both directions — which is why this is decided for you from the consent you
already hold rather than left as a checkbox.

- **Someone who agreed to marketing is subscribed properly.** Their consent is recorded at the
  destination as well as here, so your emails actually send. Previously they were only *added*
  to the list: the automation fired, the platform suppressed the message for want of consent,
  and nothing told you.
- **Someone who did not agree is left off the list.** Their profile is still created or
  updated, so everything else you mapped is there — they are simply not put on an audience they
  never opted into.

Consent is per channel: somebody who agreed to email and not to texts is subscribed for email
only.

**To record that someone has withdrawn**, open the lead, go to **Consent audit** and use
**Record a decision**. It takes effect on the very next workflow run, with nothing to edit in
the workflow itself. The consent boxes on the lead's own tab are a display of the current state
and cannot be changed there — a withdrawal has to be recorded as history, with who entered it
and when, or it is not evidence of anything. Nothing already recorded is ever altered: a person
who agreed and later changed their mind has both entries, in order.

If a list is really your own bookkeeping — "quiz-abandoned", "called back" — rather than
something you send to, set **If they have not agreed to marketing → Add them anyway** on that
step. For reaching people who have not opted in, an **event** is the better tool: record
"abandoned the quiz" and let a flow decide what to do with it.

When somebody is left off, the run log says so on that step. An empty list with a healthy run
log usually means exactly this, not a broken workflow.

## Sending email and SMS

**Send an email** and **Send an SMS** do not belong to any particular provider. They use
whichever integration you have enabled for that job, so moving to a different provider later is
a change here rather than a rewrite of every workflow.

Email works out of the box: this site's own mail settings are available as an integration
called *This site's own email*, needing no extra account. It sends one-to-one messages only —
it cannot honour a marketing unsubscribe, so it is not offered for campaigns.

If more than one integration can send email, the step asks you which. If only one can, it just
uses it.

**Patient portal links use this too.** When a patient asks to connect their record, the site
emails a one-time link through the integration you have enabled for email. It is not a workflow
step you can edit — the link is a password-strength secret — but you choose who delivers it, and
you can build workflows on *Patient was emailed a link to connect their record*, *Patient
connected their record* and *Patient verified their email*. For the link to send, exactly one
integration must be enabled for email; with none, or with two, patients are told email is
unavailable.

## When something does not work

- **A step is missing from the workflow builder.** No enabled integration provides it. Check
  the ticks under "What you use it for".
- **A run failed saying a field is health data.** The destination is not permitted for it.
  Either attest that you have an agreement, set that field to redact, or remove it from the
  mapping.
- **A run failed saying the provider cannot do something.** That operation genuinely does not
  exist at that service — not a configuration problem. Use a different operation, or a
  different destination.
- **Nothing happens at all.** Workflows run in the background, so give it a few seconds and
  refresh the run log. If runs never appear for anything, the background worker is not running
  and that is a server problem, not a settings one.
- **"Test connection" passes but everything fails with "missing required scopes".** Your API key
  is valid but was created without permission to *write*. The connection test can only tell you
  the key works, not what it is allowed to do — no service will tell us that in advance — so
  this is the one problem it cannot warn you about, and it has happened here. Most services fix
  their permissions when a key is created and never afterwards, so you cannot widen the key you
  have: create a new one with the permissions listed under the key field, and paste that in.
