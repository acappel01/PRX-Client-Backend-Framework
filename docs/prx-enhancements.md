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
| 3 | [Scored assessments (BCH Health Map)](#3-scored-assessments-bch-health-map) | Bell Curve | High | open |
| 4 | [Hero: self-hosted looping background video](#4-hero-self-hosted-looping-background-video) | Bell Curve | Medium | open |
| 5 | [API-path intake wizard (bypass the embed)](#5-api-path-intake-wizard-bypass-the-embed) | Future, all brands | Low for now | open |
| 6 | [Blog posts in sections (resolver op)](#6-blog-posts-in-sections-resolver-op) | Bell Curve, all brands | High (homepage) | open |
| 7 | [Service live-state settings (live / waitlist / coming soon)](#7-service-live-state-settings) | Bell Curve, all brands | High (client hard rule) | open |
| 8 | [Waitlist capture](#8-waitlist-capture) | Bell Curve | High (pairs with #7) | open |
| 9 | [Membership billing and founding rate](#9-membership-billing-and-founding-rate) | Bell Curve | Medium (decision first) | open: needs decision |
| 10 | [Homepage blueprint fields and BCH flexible types](#10-homepage-blueprint-fields-and-bch-flexible-types) | Bell Curve | High (after Amy approves layout) | open |

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

## 3. Scored assessments (BCH Health Map)

**Today.** The quiz module (`app/Models/Quiz/*`, `QuizQuestionKind`) supports single/multi
select, scale, measurement, text, sex, age, health goals and contact questions. Results are
resolved **by goal into catalog products/packages** (`GoalRecommendationResolver`,
`ProtocolPresenter`). There is no per-answer scoring, no result bands, and no way to recommend
**content** (blog posts, KB entries, tools) instead of products.

**Need.** Bell Curve's free Health Map is nine questions with scoring, and its results link to
recommended articles and tools. Detailed scoring rules will come from the client's education
materials.

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

1. **Answer → goal points.** New pivot `quiz_option_goal_scores`
   (`quiz_question_option_id`, `health_goal_id`, `points`). One answer can feed several goals
   (for example "waking at 3am" scores both Sleep and Stress). Edited as a repeater on the
   option in the quiz builder.
2. **Scoring mode on the quiz.** `quizzes.scoring_mode`: `none` (today's behaviour, the
   default) or `scored`. A scored quiz needs no `health_goals` question, because its goals
   come from the scores.
3. **Bands per quiz and goal.** `quiz_goal_bands` (`quiz_id`, `health_goal_id`, `min`, `max`,
   `label`, `summary` rich text, `position`, `flags_care` bool). They are scoped to the quiz
   because the score range depends on that quiz's questions. `flags_care` marks a band that
   should push the visitor toward a clinician or membership rather than only to content.
4. **Goal → content.** A morph pivot `health_goal_resources` (`health_goal_id`,
   `resourceable_type/id` over blog posts, CMS pages, KB compounds and products, optional
   `quiz_goal_band_id`, `position`). It is the "guide management" piece: an article can be
   attached to a goal for everyone or only to one band (for example a "severe" band links to
   the care page). It reuses the existing `{type, slug}` link vocabulary so the frontend owns
   routes.
5. **Service.** `AssessmentScorer` (QuizProfile/answers DTO in, `AssessmentResultData` out:
   per-goal score, max, band, ordered resources). It is pure, unit-tested and runs
   server-side, called from the existing quiz-submit action. `QuizProfile` takes its goals
   from the scorer when the quiz is scored (goals at or above a configurable band, ranked by
   score).
6. **API.** An additive `assessment` block on `GET /leads/{uuid}/plan` (and on
   `POST /protocol/preview` for an instant result before lead capture). One presenter feeds
   both, the same way as today.

Scoring stays server-side on purpose. Answers are health data, the PHI rules already
established for the quiz apply (POST bodies only, no caching, `noindex` plan page), and
computing scores in a brand frontend would duplicate the rules in every brand.

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

**Need.** The client treats this as a hard rule: Care and Membership can each be **live**,
**waitlist** or **coming soon**. Every CTA site-wide has to flip from one switch, never from a
page edit.

**Proposal.** A `ServiceAvailabilitySettings` group (or a small `services` table if brands
need more than a fixed set) with one row per service: `key`, `state`
(`live|waitlist|coming_soon`), and per-state `cta_label`, `cta_target` (an entity link),
`notice` (rich text) and `waitlist_copy`. Edited under Settings → Services and served in
`/config` as `services: {care: {...}, membership: {...}}`. CTA fields (`CtaFields`) gain an
optional `service` key, so a CTA bound to a service resolves its label and target from the
current state server-side. Frontends then never branch on state. Make it generic (a keyed
list) so any brand can gate any offering.

## 8. Waitlist capture

**Need.** First name, email, state (US), optional interest, and a separate marketing consent.
**No health data.**

**Proposal.** Reuse `EmailSubscriber` rather than `Lead`. It already has `first_name`,
`email`, `source`, `email_consent`, `consent_given_at` and UTM fields, and it sits outside the
clinical lead pipeline (no cart, no handoff, no quiz answers). Add `state` (2-letter code) and
`interest` (a `service` key from #7, or free choice from a configured list), and record
`source = waitlist:{service}`. Validate that no free-text field is offered, so nothing clinical
can land there. Expose it as `POST /waitlist` (or `POST /subscribe` with a `waitlist` block),
throttled, through `SubscribeEmailAction`. A `WaitlistJoined` event lets workflows email a
confirmation and notify the team, and later bulk-invite everyone when the service flips to
`live`.

Using `POST /leads` was considered and rejected: a lead is the start of a clinical funnel and
carries health-adjacent fields and PRX handoff semantics a waitlist must not have.

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

## 10. Homepage blueprint fields and BCH flexible types

**Source.** The Bell Curve homepage proof (acappel01/bell-curve PR #1) found these while
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

For each one: add the field to the blueprint's `formSchema()` and `defaults()`, add image keys
to `fieldKinds()`, mirror it in the shadow seed (`SectionTypeSeeder`), and keep
`SectionTypeSeedParityTest` green. None of them is a `style_*` knob, so `LayoutFieldCollisionTest`
is unaffected. `image_shape` is presentation, so it belongs in the blueprint's presentation
keys and must not count toward `has_content`.

**Six flexible types** (no backend code needed): `bch-trust-strip`, `bch-health-map-teaser`,
`bch-statement`, `bch-paths`, `bch-article-row` and `bch-founder`. They are already written in
`FlexibleSectionType` format in `acappel01/bell-curve` → `fixtures/bch/section-types.json`.
Once Amy approves the layout, they become input to a BCH install seeder (creating them as
active flexible types, then seeding the home page sections and theme palette).

Two dependencies:
- `bch-article-row` needs #6 (blog posts in sections) for its posts to inline.
  Until then it renders nothing (`has_content: false`).
- The seeder should create the types through `CreateFlexibleSectionTypeAction`, so slug
  reservation and schema validation run exactly as they do in the admin.

