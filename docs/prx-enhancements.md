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

**Proposal (generic, reusable by any brand):**

- Add per-option `score` values (and optional per-question weights) to quiz options, plus an
  optional `domain` tag on each question so one assessment can produce several sub-scores
  (for example sleep, mood, metabolic).
- Add an `AssessmentScorer` service (DTO in, DTO out) that computes total and per-domain
  scores. It should be pure and unit-testable, and called from the existing quiz submit action
  rather than from a controller.
- Add result **bands** per domain (min/max score → label, rich-text summary), all authored in
  admin.
- Add a recommendation map from band to content (blog posts, KB compounds, CMS pages, tools)
  and optionally to products. Reuse the existing `{type, slug}` entity-link vocabulary so the
  frontend owns the routes.
- Expose the result on the existing plan endpoint (`GET /leads/{uuid}/plan`) as an additive
  `assessment` block, so the BCH frontend and plan email read one shape.
- The PHI rules stay as they are: answers travel only in POST bodies, results are never
  cached, and the plan page is `noindex`.

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
