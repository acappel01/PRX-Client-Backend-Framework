# prx-backend enhancement requests

A running list of what a brand build needs from prx-backend that the framework does not do
yet. Each entry says what is missing, where that is visible in the code, what we propose, and
which brand is waiting on it. The backend team marks an entry done and links the commit or
PR; the brand team then removes its workaround.

**Status keys:** `open` · `in progress` · `done` · `won't do` (with a reason)

| # | Enhancement | Needed by | Priority | Status |
|---|---|---|---|---|
| 1 | [Embed styling: per-install CSS for the PRX embed](#1-embed-styling-per-install-css-for-the-prx-embed) | Bell Curve | High (before launch) | open |
| 2 | [Handoff page copy is hardcoded](#2-handoff-page-copy-is-hardcoded) | Bell Curve, all brands | High (before launch) | open |
| 3 | [Scored quizzes: one engine for the Health Map and Start here](#3-scored-quizzes-one-engine-for-the-health-map-and-start-here) | Bell Curve | High | open |
| 4 | [Hero: self-hosted looping background video](#4-hero-self-hosted-looping-background-video) | Bell Curve | Medium | open |
| 5 | [API-path intake wizard (bypass the embed)](#5-api-path-intake-wizard-bypass-the-embed) | Future, all brands | Low for now | open |
| 6 | [Blog posts in sections (resolver op)](#6-blog-posts-in-sections-resolver-op) | Bell Curve, all brands | High (homepage) | open |
| 7 | [Service live-state settings (live / waitlist / coming soon)](#7-service-live-state-settings) | Bell Curve, all brands | High (client hard rule) | open |
| 8 | [Leads by source (waitlist, contact and form capture)](#8-leads-by-source-waitlist-contact-and-form-capture) | Bell Curve, all brands | High (pairs with #7) | open |
| 9 | [Membership billing and founding rate](#9-membership-billing-and-founding-rate) | Bell Curve | Medium (decision first) | open: needs decision |
| 10 | [Blueprint fields and BCH flexible types](#10-blueprint-fields-and-bch-flexible-types) | Bell Curve | High (after Amy approves the layout) | open |
| 11 | [`catalog-listing` blueprint](#11-catalog-listing-blueprint) | Bell Curve, all brands | High (shop pages) | open |
| 12 | [`blog-listing` blueprint](#12-blog-listing-blueprint) | Bell Curve, all brands | High (journal page) | open |
| 13 | [Blog post body as rich text, plus a post template](#13-blog-post-body-as-rich-text-plus-a-post-template) | Bell Curve, all brands | High | open |
| 14 | [CMS pages that take over app routes](#14-cms-pages-that-take-over-app-routes) | Bell Curve, all brands | Low (mostly convention) | open |

---

## 1. Embed styling: per-install CSS for the PRX embed

**Today.** The handoff page shell (`/checkout/handoff/{lead}`) already follows the install's
theme: `resources/views/components/layouts/minimal.blade.php` reads `ThemeSettings` and
exposes colours and fonts as CSS variables. The **embed itself** is a cross-origin iframe
(`resources/views/components/prx/embed.blade.php`), so nothing on our page can style what is
inside it. Today the only way to brand the form is to paste CSS into the embed config in the
prescribe-rx admin, separately for each brand, and keep it in sync by hand.
`PrxEmbedPayloadBuilder` sends no theme or style data to the SDK.

**Proposal.** Pick one of the options below, depending on what the PRX SDK can accept:

- **A. The SDK accepts styling at init** (a CSS string, a stylesheet URL or theme tokens).
  Add an `embed_custom_css` field (and/or tokens derived from `ThemeSettings`) under
  Settings → Integrations → PrescribeRx, and have `PrxEmbedPayloadBuilder` pass it through.
  Admin becomes the single place to edit it, and theme colour changes reach the embed
  automatically.
- **B. The SDK cannot accept styling.** Still add the `embed_custom_css` field to admin as the
  source of truth, with a "copy to PrescribeRx" helper and a note that it must be pasted into
  the embed config at `/admin/embed-configs` on prescribe-rx. At least the CSS is versioned
  with the install rather than living only on the PRX side.

**Question for PRX:** does the embed SDK support a theme or CSS option at init, or a
stylesheet URL on the embed config that we could point at
`https://<backend>/embed-theme.css` (generated from `ThemeSettings`)? The URL approach would
make the PRX side a one-time setup per brand.

**For Bell Curve:** palette Warm Ivory / Warm Charcoal `#282828` / Dusty Rose `#B17270`, with
the brand's display and body fonts.

## 2. Handoff page copy is hardcoded

**Today.** `resources/views/pages/checkout/handoff.blade.php` hardcodes the eyebrow
("Step 2 of 2 · Clinical intake"), the heading ("Tell us about your health") and the intro
paragraph. That is brand voice in code, which goes against the framework's own rule that no
client-specific copy lives in code. It also cannot match Bell Curve's tone.

**Proposal.** Add the eyebrow, heading and intro (rich text) to `BillingSettings` or a small
`CheckoutCopySettings` group, editable in admin. When a field is null, render nothing (the
same rule `meta.copy` follows on the plan page) rather than a built-in default. Also consider
showing the brand logo from `BrandSettings` in the page header so the handoff page doesn't
look like a different site.

## 3. Scored quizzes: one engine for the Health Map and Start here

**Today.** The quiz module (`app/Models/Quiz/*`, `QuizQuestionKind`) supports single/multi
select, scale, measurement, text, sex, age, health goals and contact questions. Results are
resolved **by goal into catalog products/packages** (`GoalRecommendationResolver`,
`ProtocolPresenter`). There is no per-answer scoring, no result bands, and no way to recommend
**content** (blog posts, KB entries, tools) instead of products.

**Need.** Bell Curve has two scored quizzes:
- **Health Map:** nine questions with scoring. Results link to recommended articles and tools.
  The detailed scoring rules will come from the client's education materials.
- **Start here** (`/start-here`): a short routing quiz that recommends one path (Care, Shop,
  Membership or a guide) with alternatives. It scores answers into named **outcomes**, not
  health goals.

They must run on **one engine**, not two. The design below scores answers into *outcomes*.
An outcome can be a health goal (Health Map) or a free-standing result with its own copy and
CTA (Start here).

**Open dependency:** the client's locked Health Map spec (9 questions, H/M/E/A/L/G scoring
categories) is not in any of the uploads we have. Andrew is getting it from the client. The
data model below is designed to fit any category set, but the bands and points can't be
seeded until that spec arrives.

**Partial reference (2026-10-05):** a capture of the previous developer's prototype gives the
intro copy, Q1 ("What feels most different in your body right now?", choose up to 3, 11
options) and Q7 ("How would you describe where you are in figuring all of this out?", choose
one, 6 options). It holds no scoring or results. Two things in it confirm the model needs
care:
- **Multi-select questions with a per-question limit.** `MultiSelect` exists today, but the
  `QuizAnswerValidator` has no selection limit today, so add a `max_selections` config on
  the question and enforce it there.
- **"Journey stage" questions (Q7)** that steer content rather than score a symptom domain.
  Points to a goal won't fit those, so an option should also be able to carry a content tag
  that `health_goal_resources` can match.

**Proposal: extend health goals instead of building a parallel system.** Decided with Andrew
on 2026-10-04 to build this in the framework rather than locally in the brand repo.

Health goals are already the hinge of recommendations. `HealthGoal` has a hierarchy
(`parent_id`), weighted `ingredients()` that resolve to products, and `compounds()` for
education. A scored assessment should **derive** a visitor's goals from their answers rather
than ask them to pick goals. Everything downstream (`GoalRecommendationResolver`, eligibility
gating, `ProtocolPresenter`, the plan page and email) then works unchanged.

1. **Outcomes per quiz.** New table `quiz_outcomes` (`quiz_id`, `key`, `title`,
   `short_title`, `body` rich text, `summary`, `position` (the tie-break order, most important
   first), nullable `health_goal_id`, nullable `service` (a #7 key), nullable `cta_label` and
   `cta_url`). When `service` is set, the CTA comes from `/config` services, as in #7. When
   `health_goal_id` is set, the outcome *is* that goal, and its products and content come
   through the existing goal pipeline. The quiz gets `fallback_outcome_id` (used when nothing
   scores) plus `intro` and `results` copy for the first and last screens.
2. **Answer → outcome points.** New pivot `quiz_option_outcome_scores`
   (`quiz_question_option_id`, `quiz_outcome_id`, `points`). One answer can feed several
   outcomes (for example "waking at 3am" scores both Sleep and Stress). Edited as a repeater on
   the option in the quiz builder. This replaces the goal-only `quiz_option_goal_scores` pivot
   proposed earlier: a goal outcome is just an outcome row with `health_goal_id` set.
3. **Scoring mode on the quiz.** `quizzes.scoring_mode`: `none` (today's behaviour, the
   default) or `scored`. A scored quiz needs no `health_goals` question, because its goals
   come from the goal-linked outcomes.
4. **Bands per outcome.** `quiz_outcome_bands` (`quiz_outcome_id`, `min`, `max`, `label`,
   `summary` rich text, `position`, `flags_care` bool). They are optional: Start here only needs
   the top outcome, but the Health Map labels each domain. They hang off the quiz's outcome
   because the score range depends on that quiz's questions. `flags_care` marks a band that
   should push the visitor toward a clinician or membership rather than only to content.
5. **Goal → content.** A morph pivot `health_goal_resources` (`health_goal_id`,
   `resourceable_type/id` over blog posts, CMS pages, KB compounds and products, optional
   `quiz_outcome_band_id`, `position`). It is the "guide management" piece: an article can be
   attached to a goal for everyone or only to one band (for example a "severe" band links to
   the care page). It reuses the existing `{type, slug}` link vocabulary so the frontend owns
   routes.
6. **Service.** `QuizScorer` (answers DTO in, `QuizResultData` out: per-outcome score, max
   and band, the primary outcome, ranked alternatives, the derived goals and ordered
   resources). Ties break on outcome `position`, and an empty score falls back to
   `fallback_outcome_id`. It is pure, unit-tested and runs server-side. `QuizProfile` takes its
   goals from the goal-linked outcomes when the quiz is scored (at or above a configurable
   band, ranked by score).
7. **API.** Two callers, one presenter:
   - `POST /api/v1/quiz/{slug}/resolve` `{ answers }` →
     `{ primary, alternatives[], goals[], products }`. **Stores nothing** and creates no lead,
     so Start here can show a result instantly. It is throttled like other anonymous reads,
     and the response is not cached because the body is health data.
   - An additive `assessment` block on `GET /leads/{uuid}/plan` (and on
     `POST /protocol/preview`) for quizzes that do capture a lead, such as the Health Map.
8. **Selection limits.** `max_selections` on multi-select questions, enforced in
   `QuizAnswerValidator` (see the partial reference above).

Scoring stays server-side on purpose. Answers are health data, the PHI rules already
established for the quiz apply (POST bodies only, no caching, `noindex` plan page), and
computing scores in a brand frontend would duplicate the rules in every brand.

**Contract with the Bell Curve frontend** (`fixtures/bch/section-types.json` →
`quiz_additions`, `endpoint_additions`): `option.outcomes` `{ outcome_key: points }`,
`quiz.outcomes` `{ key: { title, short_title, body, summary, service | cta_label + cta_url } }`,
`quiz.outcome_order`, `quiz.fallback_outcome`, `quiz.intro` and `quiz.results`. The tables above
serialize to exactly that shape, with `outcome_order` taken from `position`.

**Why not a local or brand-only version:** it would need its own copy of goals, its own
results page and its own email, so it would duplicate the recommendation pipeline the
framework already has, and the next brand would rebuild it.

## 4. Hero: self-hosted looping background video

**Today.** `HeroSection` has `background_video_url` (a YouTube/Vimeo *embed URL*) plus
`background_image` (`app/Cms/Sections/HeroSection.php`). An embed brings player chrome, third-party
requests and a slow start, which doesn't suit a short ambient loop.

**Proposal.** Add a `background_video` media field (Curator, MP4/WebM, size-capped) with
`fieldKinds()` emitting `{url, mime, width, height}`, and keep `background_image` as the
poster and reduced-motion fallback. Keep the existing URL field for compatibility. Mirror the
change in the shadow seed so `SectionTypeSeedParityTest` stays green.

**For Bell Curve:** the homepage hero is a short muted loop (based on the approved coffee-woman
image) with a still-image fallback.

## 5. API-path intake wizard (bypass the embed)

**Today.** Admin can hold credentials for both the embed and the API at once, and
`BillingSettings::checkout_path` decides which one checkout uses. The API side already reads
the intake schema (`Client::getEncounterTypeSchema()`) and submits unified intakes
(`SubmitPrescribeRxCheckoutAction`). What is missing is a frontend-facing wizard contract: a
`GET` endpoint serving the encounter-type schema as styled, ordered steps, plus admin overrides
for labels, help text, grouping and step order, so a brand can run a fully custom intake
without the iframe.

**Not needed for launch.** Bell Curve launches on the embed and handoff page, like Atlas. This
entry is here so the design of #1 and #2 doesn't paint us into a corner. It should get its own
design doc when it's picked up.

## 6. Blog posts in sections (resolver op)

**Today.** `App\Services\Cms\SectionResolverOps` can inline products, packages and categories
(`inline_*`, `*_by_mode`, `categories`), but not `BlogPost`s. So no section, code or
flexible, can render an article row without the frontend making a second fetch.

**Need.** Bell Curve's homepage has a "What women are asking now" row, and the pathway pages
have related reading. Any brand with a blog wants the same thing.

**Proposal.** Add `inline_posts` (hand-picked ids, admin order kept) and `posts_by_mode`
(`manual | latest | category | tag`, with a `limit`), following the slider mode convention
already used by `products_by_mode`. Add a `posts` field kind for the picker. Emit the same card
shape as the blog listing endpoint (title, slug, excerpt, hero image, category, published
date), published posts only. Add `blog` cache tags so a publish refreshes the section. A
section whose query returns nothing reports `has_content: false`, as product sliders already
do, so the row hides itself until articles are published.

## 7. Service live-state settings

**Need.** The client treats this as a hard rule: Care, Shop and Membership can each be
**live**, **waitlist** or **coming soon**. Every CTA site-wide has to flip from one switch,
never from a page edit.

**Contract (agreed with the Bell Curve frontend, `fixtures/bch/section-types.json` →
`config_additions`).** `/config` gains:

```json
"services": {
  "care":       { "state": "waitlist", "actions": {
                    "live":        { "label": "…", "url": "/clinical-care" },
                    "waitlist":    { "label": "…", "url": "/clinical-care#waitlist", "status": "…" },
                    "coming_soon": { "label": "…", "url": "…", "status": "…" } } },
  "shop":       { … },
  "membership": { … }
}
```

`state` is the current one. `actions` holds the CTA for **every** state, so the frontend
renders `actions[state]` and never composes copy. `status` is an optional line shown beside the
CTA (for example "Coming soon in your state").

**Proposal.** A `ServiceAvailabilitySettings` group holding a keyed list (`key`, `state`, and a
`{label, url, status}` per state), edited under Settings → Services through a DTO and an
`UpdateServiceAvailabilityAction`, served by `ConfigController`, and clearing the config cache
on save. Keys are a list, not fixed properties, so any brand can gate any offering. Blueprints
that carry a CTA gain an optional `service` select (see #10). When it's set, the frontend takes
the CTA from `services[service]` and ignores the section's own label and URL.

## 8. Leads by source (waitlist, contact and form capture)

**Today.** `POST /api/v1/leads` is built for the clinical funnel. `LeadData` requires both
`first_name` and `last_name` (`app/Data/Leads/LeadData.php:22-24`), and the lead has no field
saying which form it came from. `landing_url` and `referrer` already exist as columns and
`LeadData` already accepts them, so attribution needs nothing new.

**Need.** Bell Curve has several short forms that are not checkout: service waitlists, the
contact page and other admin-built forms (`bch-form`, see #10). The waitlist collects first
name, email, state (US), optional interest and a separate marketing consent. **None of them
collects health data.**

**Proposal: extend `POST /leads` instead of adding a waitlist endpoint.** Agreed with the Bell
Curve frontend on 2026-10-05. It **replaces** two earlier ideas in this log: a separate
`POST /api/v1/waitlist` endpoint, and storing waitlist signups as `waitlist_entries` linked to
`EmailSubscriber`. Every form posts to one endpoint and lands in one admin list.

```
POST /api/v1/leads
{ source: contact|waitlist|quiz|checkout,
  first_name, last_name?, email, state?, service?, marketing_consent,
  form_fields: { … },          // extra fields from a bch-form, stored as-is
  landing_url?, referrer? }
```

- **`source`** is a new enum column, `LeadSource`, defaulting to `checkout` so existing callers
  behave the same. Admin's Leads list gets a filter on it.
- **`last_name`** becomes nullable on the DTO, with a conditional rule: it is still required when
  `source` is `checkout` or `quiz`.
- **`service`** becomes a dedicated nullable column, validated against the #7 keys. It is a
  column rather than a `form_fields` key because the "invite everyone when a service goes
  live" workflow needs to query it.
- **`form_fields`** is a JSON column for whatever an admin-built form adds. Its keys are
  validated against the posting `bch-form`'s `fields` list. Health data must never be added
  here, and the form builder should say so in its helper text.
- Only `checkout` (and `quiz` when it starts the plan flow) runs the clinical side effects:
  plan, PRX handoff URL and the related events. A `waitlist` or `contact` lead fires a
  `LeadCaptured` event carrying its source, so workflows can send a confirmation, notify the
  team, and invite a waitlist when #7 flips its service to `live`.
- Throttle anonymous non-checkout sources like other anonymous writes.

**Consent.** `marketing_consent` must be explicit and false by default. Joining a waitlist or
sending a contact message must never opt someone into marketing. If these leads are also
synced to `EmailSubscriber` for mailing, fix two traps in the existing subscriber code first:
- `SubscribeData::$email_consent` **defaults to `true`**. Pass the lead's
  `marketing_consent` explicitly.
- `SubscribeEmailAction` sets `consent_given_at` even when consent is false. Only set it when
  consent is actually given.

Wiring follows the usual layering: `StoreLeadRequest` → `LeadData` (with `source`, `service` and
`form_fields`) → the existing create-lead action, which branches its side effects on `source`.

## 9. Membership billing and founding rate

**Need.** Membership is $129/mo or $1,199/yr. There is also a **founding-50** rate of $99/mo,
capped at the first 50 members, and the rate is lost if the membership lapses. Membership is
non-clinical.

**Decision needed first:** is membership a **PRX plan** (billed through the embed like care) or
a **local-gateway subscription** (`checkout_path = local`, Stripe or similar)? This decides:

- who enforces the 50-seat cap and the "rate lost on lapse" rule (PRX would need to support
  both, or we track them locally);
- whether a member account lives in PRX, here, or both;
- whether a non-clinical purchase should go through a clinical intake embed at all.

**Leaning:** local subscription for membership and the embed for care. That fits only if the
framework supports a mixed checkout path per item (today `checkout_path` is install-wide).
If so, add a per-package or per-plan `checkout_path` override, a `seat_cap` and seats-taken
counter on the plan, and a `rate_lock_policy` (`lost_on_lapse`) that the rebill logic
enforces.

## 10. Blueprint fields and BCH flexible types

**Source.** The Bell Curve homepage proof (acappel01/bell-curve PR #1) and interior pages
(branch `claude/project-thread-yehma6`, `fixtures/bch/section-types.json`) found these while
mapping the approved layout onto the framework. All the blueprint changes are **additive**:
new optional fields, null by default, so existing installs serve the same payloads.

**Blueprint fields**

| Blueprint | New field | Kind | Why |
|---|---|---|---|
| `hero` | `background_image_mobile` | image | A separate mobile crop of the poster, so faces and headlines aren't cut off |
| `hero` | `background_video` `{url, type}` | media | Self-hosted muted loop (supersedes the media-field proposal in #4; keep `background_video_url` for embeds) |
| `hero` | `microcopy_link_label`, `microcopy_link_url` | text, link | The "Not sure where to begin? Start your free Health Map" line under the buttons |
| `image-text-split` | `image_shape` | select: `none`, `organic`, `arch` | Curved image masks are a core part of the BCH look. Other brands get them too |
| `image-text-split` | `side_note` | textarea (inline HTML) | A short aside next to the main copy |
| `cta-banner` | `emphasis` | text (inline) | The rose-emphasis phrase in the headline |
| `hero` | `layout` option `split` | select value | Contained image beside the copy; type-led when there is no image (interior page heroes) |
| `hero` | `image_shape` | select: `corner`, `arch`, `organic` | Curved masks on the split layout |
| `hero` | `service` | select (#7 keys) | Primary CTA and status line come from `/config` services |
| `cta-banner` | `service` | select (#7 keys) | Same as above |
| `how-it-works` | `image` | image | Optional image beside the steps |
| `how-it-works` | `theme` | select: `white`, `ivory` | Band colour |
| `features-grid` | `emphasis` | text (inline) | Rose-emphasis phrase |
| `features-grid` | `theme` | select: `white`, `ivory`, `blush` | Band colour |
| `features-grid` | `features[].eyebrow`, `features[].cta_label`, `features[].cta_url` | text, link | Per-card eyebrow and CTA |
| `features-grid` | `cta_label`, `cta_url` | text, link | Section CTA |
| `text-block` | `emphasis` | text (inline) | Rose-emphasis phrase |
| `text-block` | `link_label`, `link_url` | text, link | Trailing text link |
| `pricing-tiers` | `heading`, `emphasis`, `lead` | text, text, richtext | Section intro (membership page) |
| `pricing-tiers` | `service` | select (#7 keys) | Card CTA when a card has none of its own |
| `pricing-tiers` | `footnote` | richtext | Terms line under the cards |
| `hero` | `size` | select: `default`, `compact` | `compact` is a short page header for listing pages (#11, #12) |

`faq` and `testimonials` need no change. Where a `theme` select already exists (it does on
`image-text-split` and `cta-banner`), extend its options rather than adding a second key.
These colour selects predate the palette-named `style_background_color` knob. Consider
whether BCH should use the knob instead, to avoid adding more hardcoded theme vocabularies.

For each one: add the field to the blueprint's `formSchema()` and `defaults()`, add image keys
to `fieldKinds()`, mirror it in the shadow seed (`SectionTypeSeeder`), and keep
`SectionTypeSeedParityTest` green. None of them is a `style_*` knob, so `LayoutFieldCollisionTest`
is unaffected. `image_shape` is presentation, so it belongs in the blueprint's presentation
keys and must not count toward `has_content`.

**Eight flexible types** (no backend code needed): `bch-trust-strip` (now with a `heading`),
`bch-health-map-teaser`, `bch-statement`, `bch-paths`, `bch-article-row`, `bch-founder`,
`bch-waitlist` and `bch-form`. Both `bch-waitlist` and `bch-form` post to `POST /leads` (#8).
`bch-form` is the general form builder: a `fields` repeater (`name`, `label`, `kind`, `width`,
`required`, `options`) plus `source`, `layout`, `theme`, `aside_heading`, `aside_body`,
`show_contact` and `notice`. They are already written in
`FlexibleSectionType` format in `acappel01/bell-curve` → `fixtures/bch/section-types.json`.
Once Amy approves the layout, they become input to a BCH install seeder (creating them as
active flexible types, then seeding the home page sections and theme palette).

Two dependencies:
- `bch-article-row` needs #6 (blog posts in sections) for its posts to inline. Articles are
  published through the admin blog (confirmed by Andrew).
- `bch-waitlist` and `bch-form` need #8, and blueprints with a `service` select need #7.
- `bch-form`'s `show_contact` reads the `/config` contact group. That group **already exists**:
  `ContactSettings` serves `support_email`, `sales_email`, `phone` and `business_hours`, so
  nothing is needed beyond filling it in for the BCH install.
  Until then it renders nothing (`has_content: false`).
- The seeder should create the types through `CreateFlexibleSectionTypeAction`, so slug
  reservation and schema validation run exactly as they do in the admin.

## 11. `catalog-listing` blueprint

**Today.** Shop pages need a product or package grid with filters, and no section does that.
`product-slider` shows a fixed row. The data is already there: the `/catalog/*` list and facets
endpoints do filtering, sorting and paging.

**Need.** BCH's `/products` and `/stacks` pages are CMS pages (see #14) built from a compact
hero plus a listing section, so the admin can arrange them like any other page.

**Proposal.** A code blueprint `catalog-listing` (`SectionType` case, `SectionBlueprint`,
shadow seed). It holds **configuration only**. The frontend reads it and then calls the
existing catalog endpoints, so the section does not inline products and does not need resolver
ops or cache tags.

| Field | Kind |
|---|---|
| `kind` | select: `product`, `package` |
| `kind_tabs` | toggle (show product/package tabs) |
| `layout` | select: `grid`, `rows` |
| `columns` | select: 2–4 |
| `filter_style` | select: `sidebar`, `top`, `drawer`, `none` |
| `filters` | multi-select: `goal`, `category`, `price`, `availability`, plus a label for each |
| `show_sort`, `show_count`, `show_search` | toggles |
| `per_page` | number |
| `preset_goal`, `preset_category` | health goal / category pickers (pre-applied filter) |
| `empty_message` | text |
| `eyebrow`, `heading`, `emphasis`, `lead` | text, text, text (inline), richtext |

Layout keys (`layout`, `columns`, `filter_style`, toggles) are presentation and must not count
toward `has_content`. The section always has content, because the listing fetches at runtime.

## 12. `blog-listing` blueprint

**Today.** The same gap as #11, for the journal. `/blog` lists posts in code with no admin
control over layout or sidebar.

**Proposal.** A code blueprint `blog-listing`, configuration only, read by the frontend, which
then calls the existing blog list endpoints.

| Field | Kind |
|---|---|
| `layout` | select: `tiles`, `rows`, `magazine` |
| `columns` | select |
| `filter_style` | select: `chips`, `sidebar`, `none` |
| `sidebar_blocks` | multi-select: `search`, `categories`, `featured`, `cta` |
| `sidebar_cta_*` | heading, body, label, url for the sidebar CTA block |
| `per_page` | number |
| `preset_category` | blog category picker |
| `featured_only` | toggle |
| `show_excerpt`, `show_meta`, `show_category` | toggles |
| `link_label`, `link_url` | text, link |
| `base_path` | text (the route posts link under, default `/blog`) |
| `eyebrow`, `heading`, `emphasis`, `lead` | text, text, text (inline), richtext |

#6 (`inline_posts` and `posts_by_mode`) stays separate: it covers a fixed article *row* inside
another section, while this one is a full paged listing.

## 13. Blog post body as rich text, plus a post template

**Today.** The post body is a plain `Textarea`
(`app/Filament/Resources/Blog/Posts/Schemas/PostForm.php:60`) whose hint says "Markdown or
plain text depending on frontend rendering". So editors can't add headings, links or images
without knowing Markdown, and each frontend has to guess the format.

**Proposal.**
- Switch `content` to Filament's `RichEditor` and store HTML. Serve it as HTML from the blog
  endpoint, sanitized server-side on save (the same allow-list policy used for other rich text
  fields). Existing plain-text or Markdown bodies need a one-off migration that renders them
  to HTML, so old posts don't change appearance.
- Add a `BlogTemplateSettings` group for the per-post chrome that is not part of any one post,
  starting with the aside CTA (heading, body, label, url, and an optional `service` key from
  #7). Serve it in `/config` or on the post endpoint.
- **The `blog-post` page.** By convention the sections of the CMS page slugged `blog-post` render
  under every article (disclaimer, banners). This needs no backend change, because the frontend
  fetches that page the same way as any other. It is noted here so the admin knows the page is
  special and does not delete it. Consider marking it as a system page in the Pages list.

## 14. CMS pages that take over app routes

**Convention (agreed with the Bell Curve frontend).** A CMS page whose slug matches an
application route (`products`, `stacks`, `blog`) renders in place of the frontend's
code default. That lets the admin build the shop and journal pages from sections (#11, #12).

**Backend impact: almost none.** As of 2026-10-05, `PageForm` validates the slug as `alphaDash` and
reserves no slugs, so admin already allows these. What is left is optional:
- A hint on the slug field that `products`, `stacks`, `blog` and `blog-post` have special
  meaning on the frontend.
- If the framework ever adds reserved slugs, keep these four allowed.

