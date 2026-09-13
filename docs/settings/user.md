# Settings — User Guide

**Audience:** Site administrators / operations staff. **Permission required:** `super_admin` role (or any role with the relevant settings permission once Shield permissions are generated for these pages).

## What it is

The Settings area at `/admin/settings/*` controls the brand, look, contact info, and SEO/analytics defaults that flow through the public site and admin panel. **Everything here is database-driven** — changes take effect on the next page load with no code deploy.

## Quick map

| Section | URL | What it controls |
|---|---|---|
| **Brand** | `/admin/settings/brand` | Brand name, tagline, logo, favicon, hero image |
| **Theme** | `/admin/settings/theme` | Primary / accent / background / text colors, display & body fonts |
| **Contact** | `/admin/settings/contact` | Support & sales emails, phone, mailing address, business hours, social links |
| **Communication** | `/admin/settings/communication` | Whether email sends, mail provider, From and Reply-to addresses; Twilio SMS/voice; video consults |
| **SEO & Analytics** | `/admin/settings/seo` | Default meta title & description, OG image, Google Analytics, Tag Manager, Facebook Pixel, search-engine indexing toggle |

## How to edit

1. Sign in at `/admin/login` with a `super_admin` account.
2. In the left sidebar, open the **Settings** group and pick a section.
3. Edit the fields. Required fields are marked.
4. Click **Save** (or `Cmd/Ctrl + S`). A green toast confirms the save.

Changes are live immediately — no server restart, no cache clear.

## Field-by-field notes

### Brand

| Field | Notes |
|---|---|
| Brand name | Used in the page `<title>`, the `og:site_name` meta tag, mail-from name, and any spot the layout asks for the company name. |
| Tagline | One-line positioning. Used in OG previews and as a layout subhead. |
| Logo path | A path under `public/` (e.g. `/images/logo.svg`). Upload the file via SFTP or the media library when that ships. |
| Favicon path | Usually the same as the logo for SVG assets. |
| Hero image path | Optional fallback for OG previews and the home hero block. |

### Theme

Colors must be hex codes (`#0d0d0d`, `#c19a4b`). The five color tokens map onto CSS variables `--bg-primary`, `--text-body`, etc., used by the `data-theme="light"` / `data-theme="dark"` swap throughout the public site.

Font names should be the family name as used by `@font-face`. The actual font files live under `public/fonts/` and aren't editable from this UI yet.

#### Frontend

**Product gallery hover zoom** is off by default. Turn it on and, on desktop, hovering a
product or stack photo on its detail page magnifies the part under the cursor. It does
nothing on phones or tablets, which is why it is not on by default — and the site only
downloads the code for it while the toggle is on, so leaving it off makes every page
slightly lighter for every visitor. Turn it on if your photography rewards a close look
(texture, printed dosage text on a pen); leave it off otherwise.

The change is live on the public site within a few seconds of saving — no rebuild.

#### Colour palette

Below Typography is the **Color palette** panel (spelt that way on screen) — a list of
`{name, colour}` rows that is the site's named colour vocabulary. Names must be lowercase
slugs: `sand`, `ink`, `deep-gold`. It is what the section **Style** panel offers: when an
operator sets a section's background, text, accent, button or border colour, they pick a
**name** from this list, and the section stores the name rather than the colour.

That indirection is the point. Retune `sand` here and every section using it moves in one
edit, at the next save — no page has to be re-opened. It also means the palette is the one
place a rebrand happens.

**A colour that sections are using cannot be deleted — or renamed.** Try either and the save
is refused, with a list of the sections still holding it. This is a guard, not an
inconvenience: a section stores the name, so a rename breaks it exactly as a delete would,
and neither can be recovered from on the website side — the band would render **transparent**
while still claiming a colour was chosen. Retire a colour by pointing those sections
somewhere else first, then removing the row.

The check looks inside cards nested within sections as well as sections themselves, so a
colour used only by a hero highlight card is still protected.

### Contact

Email and URL fields are validated. Country code is the ISO 3166-1 alpha-2 code (US, CA, GB, …). Social URLs that you leave blank are skipped in the rendered footer/nav.

**Support email** is also where replies to the site's emails go, unless Communication → Reply-to address says otherwise (below).

### Communication — Email

`/admin/settings/communication`. Every field here is optional; a blank one means "keep what the server is configured with".

| Field | Notes |
|---|---|
| Send email | The master switch. **Off means nothing is sent** — plan emails, and the patient portal's record-linking emails (which then answer "unavailable"). |
| Provider + its credentials | Only the selected provider's fields are shown. Switching provider does **not** erase the other provider's saved key. |
| From address | Must be on a domain your provider has verified, or mail is rejected or spam-filed. Use a no-reply address there, e.g. `no-reply@mg.example.com`. |
| From name | Defaults to your brand name. |
| Reply-to address | Where a recipient's reply lands. Any mailbox you actually read, on any domain — it has no effect on deliverability. **Blank uses Contact → Support email.** If both are blank, a reply goes to the From address, which normally nobody reads. Patients may reply with health details, so point this at a mailbox covered by your privacy agreements — not a helpdesk tool you have no BAA with. |
| Reply-to name | Optional label for the reply address, e.g. "Support". It is not borrowed from the From name. |

**Queued mail picks up a change after a worker restart.** Plan emails are sent by the background queue, which read these settings when it started; ask whoever runs the server to restart it (`php artisan horizon:terminate`) after changing the sender or reply-to. Record-linking emails are sent immediately and use the new values at once.

#### Where every email setting lives

Admin settings win over the server file (`.env`); a blank admin field falls through to the next fallback in the same row, ending at the server column. Server-file values need someone with server access, then a Horizon restart.

| What | Set it in the admin | Server fallback (`.env`) | Elsewhere |
|---|---|---|---|
| Whether anything sends | Settings → Communication → **Send email** | — (off until switched on) | Also counts as off: no Provider chosen while the server mailer is `log` or `array`, which accept mail and deliver nothing |
| Which service sends | Settings → Communication → **Provider** + credentials | `MAIL_MAILER`, `MAILGUN_DOMAIN`, `MAILGUN_SECRET`, … | Sending domain verified in the provider's dashboard (SPF/DKIM DNS records) |
| From address | Settings → Communication → **From address** | `MAIL_FROM_ADDRESS` | Must be on the verified sending domain |
| From name | Settings → Communication → **From name**, else Settings → Brand → **Brand name** | `MAIL_FROM_NAME` | |
| Reply-to address | Settings → Communication → **Reply-to address**, else Settings → Contact → **Support email** | `MAIL_REPLY_TO_ADDRESS` | If the reply address is on a domain whose MX records point at the provider, the provider receives its mail: add an inbound **route** there (Mailgun: Receiving → Routes) forwarding it to a real inbox, or replies are dropped |
| Reply-to name | Settings → Communication → **Reply-to name** | `MAIL_REPLY_TO_NAME` | |
| Which integration sends portal mail | Automation → **Integrations**: exactly one integration switched **On** (Enabled) offering transactional email | — | The same one-only rule applies to workflow email steps that don't name an integration |
| Link in the plan email | — | `CMS_FRONTEND_URL` | Unset: the email still sends, but its link points at the admin, not the site |
| Link in the record-linking email | — | `PATIENT_PORTAL_URL` | Unset: no link is sent; the portal is told email is unavailable |

Mail the provider receives for a no-reply address is not forwarded anywhere unless a route says so. That is the intent for a no-reply, but a route that forwards it to support (or auto-answers "this inbox isn't monitored") catches patients who reply to the From address anyway.

### SEO & Analytics

| Field | Notes |
|---|---|
| Default page title | Used as `<title>` when an individual page doesn't override it. |
| Default meta description | Used as `<meta name="description">` and OG description. |
| Default OG image | Used as `og:image` when a page doesn't override. |
| GA4 measurement ID | `G-XXXXXXX`. When set, the layout emits the GA4 snippet. |
| GTM container ID | `GTM-XXXXXXX`. When set, the layout emits the GTM snippet. |
| Facebook Pixel ID | When set, the layout emits the Pixel snippet. |
| Allow indexing | Off in staging / pre-launch. When off, every page emits `<meta name="robots" content="noindex, nofollow">`. |

## Common operations

### Take the site out of search-engine indexing

`/admin/settings/seo` → toggle **Allow indexing** off → Save. Confirm by viewing the public source and looking for `<meta name="robots" content="noindex, nofollow">`.

### Replace the brand mark

Upload your new logo to `public/images/your-logo.svg` (SFTP). Then in `/admin/settings/brand` set **Logo path** to `/images/your-logo.svg` and Save. Hard-refresh the public site.

### Stop using a Google service

Clear the relevant ID field (GA / GTM / Pixel) and Save. The layout stops emitting the snippet immediately.

## Limits

- These settings are **single-tenant per deploy** — there is no per-user or per-region override.
- Settings are NOT versioned or audit-logged in this iteration. (Coming with the Audit module.)
- Image uploads from the UI are NOT yet supported — paste a path string. (Coming with the Media Library module.)

## Troubleshooting

- **Saved but the public site didn't change** — full-page-refresh. If still stale, check whether view caching is on (`php artisan view:cache` was run); run `php artisan view:clear` to invalidate.
- **Validation error toasts** — the form schema validates first, then the DTO validates again. The error message names the field. If a field appears valid but the toast persists, check that no leading/trailing whitespace snuck in.
- **403 on `/admin`** — your account doesn't have the `super_admin` role. Ask another `super_admin` to assign it via Shield's user-roles UI.


## LLM API keys

Claude and OpenAI API keys saved through the LLM settings page are encrypted in the database. Existing Claude credentials on installs predating the 2026-09-06 encryption migration may remain in older plaintext database backups. Revoke/rotate the old key at its provider and save the replacement in the settings page. The migration protects storage going forward; it does not rotate a key or alter old backups.
