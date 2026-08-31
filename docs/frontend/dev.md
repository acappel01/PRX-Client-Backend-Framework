# Frontend Implementation Guide (for a new company / deployment)

Status: current as of 2026-08-15

This guide explains how a company deploying its own prx-backend instance builds (or commissions) the public-facing frontend. The backend is **headless**: it serves a versioned REST API (`/api/v1`) plus the Filament admin panel. The frontend is a **separate application in its own repository, running on its own origin** — typically the company's apex domain (`www.company.com`) with the backend on a subdomain (`api.company.com` or `admin.company.com`). It is *not* a path under the Laravel app.

Any stack that can call HTTP works. The reference skeleton is Next.js (App Router, React Server Components) — see the companion frontend repo's `README.md` and `lib/api.js`.

## 1. Core contract

- **Base URL**: `https://<backend-host>/api/v1`
- **Envelope**: success → `{ data, meta?, message? }`; failure → `{ message, errors? }`. Always unwrap `data`.
- **Versioning**: breaking changes bump the prefix to `/v2`. Pin to `v1`.
- **Live reference**: interactive OpenAPI docs at `https://<backend-host>/api/docs` (Scalar UI, generated from code).
- **Caching**: content endpoints are server-cached (~300s) and invalidated on admin edits. Frontends should still cache/ISR on their side (the reference skeleton uses `revalidate: 300`).

## 2. Authentication & origins

Public content reads (config, pages, layout, menus, catalog, blog, FAQ, profiles) require **no token**.

For production installs, issue an **ApiClient token** in the admin (API Clients section) and send it as `Authorization: Bearer <token>`. When an ApiClient has an `allowed_origins` list, any request bearing its token must also carry a matching `Origin` header — this is how an install pins its API to its own frontends. Patient-portal endpoints (`/patient/*`) use their own patient token flow; carts use an anonymous `X-Cart-Token` (below).

Keep the token server-side (env var). Never ship it in client-side JavaScript.

## 3. Boot: one config call drives all branding

`GET /api/v1/config` (cached 5 min) returns everything install-specific:

| Key | Contents | Frontend responsibility |
|---|---|---|
| `brand` | name, tagline, logo variants, favicon, hero image, announcement | Render chrome; never hardcode a brand string or logo file |
| `theme` | `primary_color`, `accent_color`, `accent_secondary_color`, `background_color`, `text_color`, `font_display`, `font_body`, `custom_css`, `frontend_template`, `product_zoom_enabled`, `palette`, `text_classes` | Map colors/fonts to CSS custom properties on `<html>`; inject `custom_css` **after** your own styles; switch component/layout variants on `frontend_template`. `product_zoom_enabled` (bool, default `false`) asks for a hover magnifier on catalog detail imagery — treat it as a payload-weight decision, not a CSS one: load whatever library implements it **dynamically**, so `false` means the code is never downloaded rather than merely never bound |
| `contact` | emails, phone, address, business hours, social links | Contact page, footer, JSON-LD |
| `seo` | default title/description, OG image, `google_analytics_id`, `google_tag_manager_id`, `facebook_pixel_id`, `tiktok_pixel_id`, `custom_head_scripts`, `custom_body_scripts`, `allow_indexing` | Metadata defaults, robots handling, analytics bootstrapping. Inject the custom script fields verbatim (head / end-of-body) |
| `provider` | telehealth provider name/slug, `supports_embed`, `supports_patient_portal_auth` | Feature-gate checkout embed and patient portal |

**Trust note on `custom_css` / `custom_*_scripts`:** these execute verbatim in the page. They are writable only by the install's permission-gated admins and served from the install's own backend — the same trust level as the frontend deploy itself. Never point a frontend at a backend you don't control.

**Colour palette:** `theme.palette` is the install's named colour vocabulary — a list of `{name, color}` rows managed in Settings → Theme. It is the vocabulary the section **style knobs** resolve against, so a frontend that ignores it renders every `style_background_color: "sand"` as nothing. Expose each entry two ways:

- a custom property `--palette-{name}: {color}` on `<html>`, which is what the style knobs reference;
- a `.tx-{name} { color: … }` rule, so admins can colour runs of rich text with `<span class="tx-gold">…</span>` without touching CSS.

Sections store the colour's **name**, never a hex — retuning a palette entry in the admin is meant to move every section using it. Sanitize `name` and `color` before injection: both reach a `<style>` tag and an inline style attribute.

`theme.text_classes` is the pre-palette name for the same rows and still ships, in lockstep, for frontends built before the palette existed. New work should read `palette`.

**Long-copy fields** (hero slide `description`, image-callout `content`, timeline `body`, hero-banner `subhead`) are HTML — and see §4b for the presentation knobs every section carries: render them with the shared `Html` component (plain legacy text passes through with newlines converted to `<br />`).

**Theming model:** the frontend defines *structure* with neutral CSS variables (`--color-primary`, `--font-display`, …); the backend supplies *values*. Per-install visual identity therefore requires zero frontend code changes: colors/fonts via theme settings, arbitrary overrides via `custom_css`, and wholesale layout swaps via `frontend_template` (the frontend maps each supported template slug to its own component set; unknown slugs should fall back to `default`).

## 4. Pages & sections (CMS)

- `GET /pages` — published page index (no sections).
- `GET /pages/{slug}` — `{ title, slug, title_banner, seo, sections: [envelope…] }`.

Each section **envelope** is `{ type, origin, anchor, global, has_content, data, schema? }`:

- `origin: "code"` — one of the built-in blueprint types (hero, faq, testimonials, product-slider, …). Render with a dedicated component per `type`. Product/package types arrive with full catalog card data already inlined in `data`.
- `origin: "flexible"` — an admin-defined type. `schema` is a field-kind map (`text`, `richtext`, `image`, `link`, `boolean`, `select`, `svg`, `repeater`, `products`, `packages`) — render generically from it.
- **`has_content: bool` — render nothing when this is `false`.** A section an editor added but never filled in still carries its blueprint's structural flags (`theme: "light"`, `alignment: "left"`, `mode: "manual"`), so a naive "is every value empty?" check judges it authored and an empty scaffold reaches the live page. The backend knows which of its own keys are presentation and does that classification for you — it is computed after catalog inlining, so a slider whose query returned nothing is correctly `false`. Do not reimplement this by guessing which keys look like flags.
- `anchor` → element `id`; `type` / `global.slug` → CSS hooks (`section--{slug}`); `global` marks shared blocks.
- Image kinds arrive resolved as `{ id, url, alt, width, height }`; SVG fields arrive sanitized; unknown types should render a visible placeholder in dev builds, never crash.
- `data` may carry a **`children`** array of typed sub-blocks — see §4c. It is absent unless the section holds blocks, so ignoring it keeps a consumer working exactly as before.

### 4b. Presentation knobs every section carries

`SectionFormBuilder` injects two shared panels — **Layout & spacing** and **Style** — into *every* section type, so these keys live in the same flat `data` payload as the type's own fields. They are the operator's design controls; the frontend owns what each token measures.

| Key | Values | What it controls |
|---|---|---|
| `content_inset` | `flush` `none` `sm` `md` `lg` `xl` | Horizontal inset of the section's **content** (backgrounds stay full-bleed). `flush` counter-bleeds the page gutter so content reaches the viewport edge — **sections only, see §4c** |
| `content_width` | `narrow` `medium` `wide` `xwide` `full` | Max-width cap on the content column, centred |
| `content_align` | `left` `center` `right` | Text and grid/flex item alignment |
| `media_width` | `contained` `full` | Whether the section's media escapes the content column |
| `style_padding_top` | `none` `sm` `md` `lg` | Vertical padding above the section band |
| `style_padding_bottom` | `none` `sm` `md` `lg` | Vertical padding below the section band |
| `style_border_color` | a `palette` entry **name** | Border colour of the section band |
| `style_border_width` | `none` `sm` `md` `lg` | Border thickness; inert without a colour |
| `style_radius` | `none` `sm` `md` `lg` | Corner radius of the section band. A rounded band is a CARD: it stops running edge to edge and comes to rest inside the page gutter, because a radius at the viewport edge reads as a curve cut out of the page. `none` is not the same as unset — it explicitly keeps the band full-bleed |
| `style_background_color` | a `palette` entry **name** | Background colour of the section band |
| `style_background_width` | `full` `contained` | Where that colour stops. `full` (default, and what an unset value means) reaches the viewport edge; `contained` paints the content column instead, so the colour follows `content_width` and `content_inset` rather than spanning past them. Inert without a colour. **Section-level only** — a block's background already stops at its own box. A section with no `.sx-content` (`stats-marquee`) degrades to full-bleed rather than losing its colour |
| `style_text_color` | a `palette` entry **name** | Colour copy inherits within the section |
| `style_accent_color` | a `palette` entry **name** | Eyebrows, emphasised words, stat figures |
| `style_button_color` | a `palette` entry **name** | Button fill; the label colour is derived, not stored |
| `style_background_image` | resolved `{id, url, alt, …}` | Image behind the section band |

**`extra_padding` is retired.** It was one token driving both vertical edges at once;
`style_padding_top` / `style_padding_bottom` replace it. It was set on no live row — only on
the Atlas `/test-page` bench — so there is no compatibility shim and a consumer should treat
the key as unknown.

**There is deliberately no `style_padding_left` / `_right`.** The horizontal edges belong to
`content_inset`, which acts on the content column. Padding on the knob wrapper narrows the
containing block of the section band, and the band's own bleed is a fixed
`-1 * --page-gutter` that recovers the gutter but not the knob — so a horizontal padding knob
leaves every self-painting section inset from the viewport edge by exactly the padding chosen.
One pair of horizontal controls, on the box that can move safely.

#### Per-breakpoint overrides

Four of these knobs may also arrive **suffixed** with a breakpoint tier:

| Base key | Also | Applies from |
|---|---|---|
| `content_inset` | `content_inset_md`, `content_inset_lg` | 768px, 992px |
| `content_align` | `content_align_md`, `content_align_lg` | 768px, 992px |
| `style_padding_top` | `style_padding_top_md`, `style_padding_top_lg` | 768px, 992px |
| `style_padding_bottom` | `style_padding_bottom_md`, `style_padding_bottom_lg` | 768px, 992px |

- **The base key is the value at every width**, so it is the MOBILE value. A payload with no
  suffixed keys behaves exactly as it did before they existed — this is additive, and a
  consumer that ignores the suffix keeps working.
- **A suffixed key holding `null` means "inherit the width below", not "reset".** That is why
  every size scale carries an explicit `none`: without it an operator could add padding on
  mobile with no way to remove it on desktop.
- **Suffixes are flat, never nested.** They are ordinary sibling keys in the same `data`
  payload, which is what keeps `has_content` a name-check — see the rules below.
- `content_width` takes no override: a max-width cap is inert below the cap, so a narrower
  override is a no-op. Colours take none either — the form would be unusable and it would drag
  the palette-deletion guard into suffix matching.
- The tier names are Bootstrap 5.3's, matching the frontend's own breakpoint scale. A third
  tier is one key per field and needs no shape change.

Four rules that make these safe to consume:

1. **Width values are semantic tokens, never pixels.** prx-backend serves more than one frontend, so a measurement belonging to any one of them may not appear in its code. You own the token → px map and can retune the whole scale without a content edit.
2. **Unset means "keep this section type's own design".** Emit a class or property *only* when a knob resolves to a value. A rule written unconditionally against an unset custom property resolves to `unset` and will blank whatever the section's own stylesheet painted.
3. **Colours are stored by name, not by value**, and resolve through `--palette-{name}`. That indirection is the point: it is what makes a palette edit reach every section.
4. **Style keys are namespaced `style_*`, deliberately.** `background_image` is already an *authored content* field on `hero`, `cta-banner` and `image-callout-banner`. An unprefixed knob collides with it in the flat payload — which both reclassifies those sections' real images as presentation (so `has_content` goes false and the section vanishes) and paints the content image a second time as a backdrop. `LayoutFieldCollisionTest` enforces the prefix; do not strip it.

None of these keys count as authored content: a section carrying nothing but style knobs still reports `has_content: false` and must render nothing.

### 4c. Sub-blocks — typed children inside a section

A section's `data` may carry a `children` array of **typed child blocks**. It is present only
when the section actually holds blocks; a section without them serves exactly what it served
before sub-blocks existed, so this is additive and no consumer has to change to keep working.

Each child is a mini-envelope:

```json
"children": [
  {
    "type": "testimonial",
    "has_content": true,
    "data": { "title": "…", "subtitle": "…", "quote": "<p>…</p>", "image": { "url": "…" },
              "content_width": "full", "style_background_color": "sand" }
  }
]
```

- **`type`** is the block slug — dispatch on it exactly as you dispatch a section on its own
  `type`. Block slugs live in their own namespace and never collide with section slugs.
- **`has_content`** obeys the same rule as a section's: **render nothing when it is `false`.**
  It is computed per child against that block type's own presentation keys, so a child holding
  nothing but knobs is correctly empty — and a section whose only children are empty reports
  `has_content: false` itself and must not reach the page.
- **`data`** carries the block's own fields **plus the same knobs from §4b**, with the same
  meanings and the same "unset means keep the design" rule. A block's knobs are the operator's
  way of positioning and colouring the child *within* its parent. That includes the
  per-breakpoint suffixes — a child may carry `content_align_md` exactly as a section does.
- **One exception: `content_inset: flush` is never served on a child.** `flush` cancels the
  page gutter, and a child sits inside its parent's content column rather than against the
  viewport edge, so the value would have nothing to cancel. The admin does not offer it on a
  block; a consumer should not build a child-level rule for it.
- Image kinds inside a child arrive resolved just as they do on a section.
- A child whose block type no longer resolves is **dropped server-side**, not served raw. You
  never need a placeholder for an unknown child type the way you do for an unknown section
  type.

Two constraints worth stating because they are easy to get wrong:

1. **`children` is a reserved key.** It is resolved structurally — anything stored there that
   is not a list of typed `{type, data}` items is **dropped on serve**, not merely shadowed —
   so no section or block type may declare an authored field of that name. Same discipline as
   the `style_*` prefix, and guarded the same two ways: `LayoutFieldCollisionTest` for code
   blueprints, and `FlexibleSectionTypeForm::reservedFieldKeys()` at the point an operator
   types a key, which is the only place a type created at runtime can be caught.
2. **Nesting a child's knobs is not the same as nesting a section's.** If your knob CSS is
   written with descendant selectors scoped to a page-level band, a nested child will inherit
   its parent's inset/width/align and bleed out of the parent's column. Give children their
   own class vocabulary, or scope with child combinators — do not simply reuse the section
   classes on a child.

Sub-blocks are a **code-blueprint capability**. Admin-defined flexible types fan one shared
child schema across a repeater and cannot express heterogeneous typed children, so a flexible
type never serves this key.

### 4a. Authored copy is HTML — never render it as text

Every text field an operator can type into is authored in a rich editor and
arrives as an **HTML string**. Rendering one as a plain text node escapes the
markup and prints the tags on the page (`The Operating System&lt;br /&gt;for
Longevity`). This is the single most common integration bug against this API.

Fields come in two kinds, and the kind tells you what markup you may receive:

| Kind | Example fields | You will receive | Render it as |
|---|---|---|---|
| **inline** | `heading`, `headline`, `eyebrow`, `emphasis`, `title`, `label`, `value`, `meta`, `caption`, `badge`, `q`, `name`, `quote`, `text` | Inline markup only — `<b> <strong> <i> <em> <u> <s> <a> <br> <span> <sup> <sub> <small> <code> <mark>` | Inject into an element **you** choose (`<h1>`, `<h2>`, `<li>`, `<span>`) |
| **prose** | `body`, `content`, `description`, `bio`, `a` (FAQ answer) | Block markup — paragraphs, `<h2>`/`<h3>`, `<ul>`/`<ol>`, `<blockquote>`, plus all inline tags | Inject into a container of its own (a `<div>`) |

**The inline guarantee is load-bearing.** The backend strips block markup from
inline fields on save, so you can safely put the value inside a heading you
picked yourself without risking `<h1><h2>…</h2></h1>` and a corrupted document
outline. In exchange, do not wrap a *prose* value in a `<p>` or `<h2>` — it
already carries its own blocks, and nesting them is invalid.

Normalization happens on write (`App\Cms\Support\HtmlCopy`), so the payload is
already in the promised shape — a frontend does not need to sanitize or unwrap.
Two details worth handling anyway:

- **Legacy plain text.** Values authored before a field became a rich input are
  stored as plain text with real newlines. Convert `\n` → `<br />` when the
  value contains no markup, so those keep their line breaks.
- **Empty means empty.** A field carrying only empty markup normalizes to
  `null`, so a null check is enough — you will not receive `<p></p>`.

Trust model: this is permission-gated admin HTML from the install's own
backend, the same path as `custom_head_scripts`. Inject it directly. Never
route user-generated content through the same path.

Route pattern: a catch-all route mapping URL path → page slug, plus `/` → slug `home`. Per-page `seo` overrides the config defaults; respect `noindex`.

- `GET /layout` — six fixed regions (`top_bar`, `header`, `pre_footer`, `footer`, `sidebar_left`, `sidebar_right`; keys always present). Items are `{kind: "section"|"menu", …}` — sections use the same envelope; menus embed a tree.
- `GET /menus/{slug}` — menu tree. Entity links emit `{type, slug}` (`page`, `product`, `package`, `catalog_category`, `blog_post`, `blog_category`); **the frontend owns the route patterns** (e.g. `product` → `/products/{slug}`). `url`/`anchor` links emit `{type, url}`. Unpublished targets are already dropped server-side.

## 5. Datasets

| Endpoint | Notes |
|---|---|
| `GET /catalog/products` (+`/{slug}`) | Paginated; filters: `category`, `tag`, `search`, `price_min/max`, `featured`, `in_stock`, `per_page`. Prices as `{retail, sale, effective, suffix, currency}`. Stock: `is_in_stock` is the boolean to branch on; `inventory_status` is the raw enum case and `inventory_status_label` its display string — **render the label, never the bare value** — and both are null on the many products where an operator has not set one |
| `GET /catalog/packages` (+`/{slug}`) | Packages with member products and `plans` (billing period, term, recurring flag, trial). Three separate price fields — see **Package pricing** below; do not derive one from another |
| `GET /catalog/categories`, `/tags` | Taxonomy for navigation and filter facets |
| `GET /catalog/facets` | Filter-sidebar option lists and counts, plus **two** price blocks: `price` spans published PRODUCTS, `package_price` spans published PACKAGES by the same `price_from` figure their cards show. **A package listing must read `package_price`** — feeding it `price` labels a product range while the endpoint filters package figures. Every other group in this payload is still product-scoped: counts are product counts, and a category or tag attached only to packages is omitted entirely |
| `GET /blog/posts` (+`/{slug}`), `/blog/categories`, `/blog/tags` | `content` only on show route |
| `GET /faq`, `/faq/categories` (+`/{slug}`) | Central FAQ dataset |
| `GET /profiles` (+`/{slug}`) | People (doctors, executives, team) with typed roles |
| `GET /health-goals` | The intake quiz's choices. Unpaginated; `all=1` includes goals withdrawn from the quiz, `tree=1` nests children. `prompt` is the visitor-facing wording and falls back to `name` — render it, not `name`. **The ingredient/product/compound mappings are deliberately absent**: recommendations are derived server-side |
| `GET /kb/compounds` (+`/{slug}`) | Compound monographs. Paginated; filters: `search`, `peptides_only` (**defaults true**), `regulatory_status`, `sort`, `per_page` (1–100, default 24). The eight prose sections, `clinical_references` and `seo` are on the show route only — roughly 28,000 characters per compound. `provenance` ships on BOTH routes |

**Package pricing — three fields, three questions, and they are not interchangeable.**

A package is buyable two ways: on its own, and through a plan. Both routes emit all three
fields whenever the `plans` relation is loaded (it always is on these endpoints), including
when a package has no plans at all.

| Field | Shape | Answers |
|---|---|---|
| `price` | `{retail, sale, effective, suffix, currency}` | What one purchase of the package itself costs. `effective` is `sale ?? retail`, and is `null` — never `0.00` — when unpriced |
| `price_range` | `{from, to, currency}` | The full span a visitor could pay, across plans **and** the package's own price |
| `price_from` | `{amount, suffix, plan_id, currency}` | The cheapest way in ("as low as $X"), the unit it is charged in, and — when it came from a plan — which plan. **Products carry this too** |

**`price_from` is NOT `price_range.from`, and a card must not use the range.** The range's two
ends are routinely in different units: on a typical install the low end is a monthly rate and
the high end a multi-month prepay TOTAL, so rendering the span produces "$279.99 – $1,259.96"
and tells a visitor a stack might cost $1,259.96 a month. The range is still correct for what
it measures — it is the honest answer to "what could I pay" — but only `price_from` is safe on
its own.

**`price_from` is the cheapest way in, and a card renders it as "as low as $X".** The pool is
the item's own price together with its **monthly-cadence** plans; the lowest wins. An item is
buyable at its own price (once) or, if it carries plans, at a plan's price (a recurring or
prepaid commitment), and the card advertises the floor across both — so the number is one the
visitor can actually reach, not a claim about what they will pay.

**Only monthly-cadence plans join the pool, and that guard is what makes the figure showable
alone.** A plan's cadence is structural (`billing_period`); raw amounts are not comparable
across billing units. Term plans are typically 3/6/9/12-month **prepay totals**, so pooling them
unfiltered lets a $537.30 quarterly total look cheaper than a monthly rate. The item's own price
is always a candidate — it has no cadence column to filter on, and excluding it would hide the
case a sale exists to create: a single purchase discounted below every plan.

**The fallback:** an item sold only as a prepay term has no monthly price, and rendering nothing
would hide something purchasable, so it takes the cheapest price of any cadence with that
price's suffix — "as low as $899.00/6mo", which is true, rather than "/mo", which is not. It
cannot fire for an item that has an own price. `amount` is `null` when nothing is priced
anywhere.

**Both products and packages carry `price_from`.** One rule for what a card quotes; having two
was how the same item came to show different numbers on different screens.

**Intro prices are excluded from `price_from` on purpose.** A plan's `intro_price` buys one
billing cycle, so leading a card with it advertises a number the visitor stops paying. Render
it on a detail page's plan picker, where the term is visible, not on a card.

**DO NOT SILENTLY ADD THIS FIGURE TO THE CART.** It is a floor, and on a typical install it
names a recurring **plan** — so adding on the visitor's behalf enrols them in a rebill they
never chose, while adding the item alone charges more than the card just quoted. Neither is
acceptable. Send the visitor to the detail page to choose a term, or give them a plan picker
that opens on `plan_id`. A card may quote this figure freely; only the *purchase* needs a
decision the visitor made.

**`plan_id` names where the figure came from**, and `null` means the item's own price — bought
once, no plan, no rebill. It is not a formatting flag: "as low as" is true of a single price
too. It is what a plan picker opens on, and what tells the cart whether a rebill is involved.
Do not reverse-engineer it by matching the rendered string against plan prices — this field is
the answer already computed.

**The own price's suffix is free text and is passed through unvalidated.** An item's own price
has no cadence column — nothing in the backend can tell `/mo` from `/ea` or know whether either
is true — so `price_suffix` arrives exactly as an operator typed it and may be absent. A
one-time price should normally carry **no** suffix, because it is not charged per period. Render
it verbatim and do not supply a default: a card reading "$399.00/mo" for a purchase the cart
books once is a content bug with a content fix, and a frontend fallback would hide it.

Products get no `price_range` — that field is package-only — but they **do** carry `price_from`,
computed by the same rule. It is emitted only when the product's `plans` relation is loaded, and
omitted silently otherwise; a card that falls through to `price.effective` will then disagree
with the product's own page. The monthly-cadence filter is what makes this safe for products in
particular: their term plans are 3/6/9/12-month prepay totals, and pooling those by raw amount
is exactly the mixed-unit problem these fields exist to avoid.

**A product listing deliberately ships `price_from` but not `plans`.** The relation is loaded to
compute the figure; the array itself is on the show route only.

**Knowledge base, two things a frontend must get right:**

- **A monograph is public only when it is published AND has a regulatory status.** Both routes
  enforce it; an incomplete compound is a **404**, not a 403 — the existence of an unpublished
  draft is not public information. Treat `null` from the show route as "not public", not "does
  not exist". A clinician reviewer is **optional**: `reviewed_by` is often null and the page
  must render without it.
- **`provenance` is a trust signal, not a disclaimer, and it ships on both routes.**
  `provenance.source_count` is how many clinical sources the monograph was summarised from —
  surface it. It is null when the source did not record one; render nothing rather than "0".
- **`regulatory` is an object, not a string**: `{value, label, description,
  is_approved_for_human_use}`. Render `label` and `description` as given rather than mapping
  `value` to your own copy — a status added here would otherwise render as unstyled text — and
  show a visible not-approved notice whenever `is_approved_for_human_use` is false. It is the
  most consequential fact on the page and it should not be buried in the prose.

`peptides_only` defaults **on**, because the library is largely antibiotics, vitamins and
topicals and the default answer to "what is in the knowledge base" is the peptide wiki. Pass
`peptides_only=0` anywhere you need the full library — a sitemap in particular, since every
published monograph is a real URL.

Cache tags: `kb` (broad) and `kb:{slug}`. Both are pushed on every save.

### 5b. The `quiz` section type

`quiz` mounts the intake wizard **inside a CMS page**. It is distinct from `quiz-cta`, which
is the ingress and only links to it — a funnel usually wants the ingress on the home page and
the quiz on one dedicated page.

`data`: `eyebrow`, `heading`, `heading_level` (`h1|h2`), `body`, `goals` (list of goal slugs).

Two things a frontend must get right:

- **It renders with an entirely empty payload.** The blueprint declares
  `hasIntrinsicContent()`, so the envelope arrives with `has_content: true` even when every
  copy field is null. Do not add a local "no heading → render nothing" guard, which is the
  usual and correct pattern for editorial sections: here it would delete a working quiz.
  The one thing that legitimately renders nothing is having no goals to offer.
- **`goals` empty means ALL quiz goals**, not none. Fetch `/health-goals` and filter by the
  slugs only when the list is non-empty, so a goal the operator adds later appears without an
  edit to every page carrying a quiz — and a goal they withdraw from intake disappears from
  both, because it stops appearing in `/health-goals` at all.

### 5a. Protocol preview — resolving a goal for a visitor

`POST /protocol/preview` — body `{goals: string[], sex?: string|null, age?: int|null}`.

Turns the goals a visitor picked into what they may actually be offered, filtering on sex and
age eligibility before ranking. This is the endpoint the intake quiz calls after its questions.

**It is a POST and it must stay one.** As a GET, `?goal=sexual-wellness&sex=male&age=62` lands
in every access log, proxy log and analytics row between the browser and the origin — a health
inference about an IP address. The response is per-visitor and uncacheable, so a GET buys
nothing. **Do not cache the response**, and do not put the answers in a URL, browser history, or
any analytics payload.

**It stores nothing.** A preview creates no record. Answers become data only when a lead is
submitted, which is a separate, consented step.

Each entry in `data` carries an `outcome` — the three states a funnel must tell apart:

| `outcome` | Meaning | What to render |
|---|---|---|
| `matched` | Something is suitable | The `products` and `packages` |
| `restricted` | Something exists, but not for this visitor | An honest message + a route to a clinician. **Not** an error, and not "nothing found" |
| `unmapped` | Nobody has built this goal out yet | "we're still building this out". An operator problem, never a rejection |

`restricted` and `unmapped` both come back with zero products and need completely different
copy. Do not infer the state from `products.length` — read `outcome`.

`meta.filtered` says whether any filtering was actually applied. Use it to decide between
"based on what you told us" and a neutral heading; claiming personalisation you did not perform
is worse than not claiming it.

**Omitting `sex`/`age` filters nothing.** Null means "not asked", not "answered nothing" — a
visitor who skipped the questions gets the unfiltered shelf. Never send a guessed value to
"fill in" a missing answer; an unrecognised free-text answer is also treated as no answer rather
than being mapped into a bucket.

`excluded_count` is a **count, not a list**, deliberately. Which ingredients were excluded is
not returned, because it would let anyone enumerate the sex- and age-gated substances by varying
the request.

### 5c. The saved plan — a lead's matched protocol

`GET /leads/{uuid}/plan` — the same answer as 5a, for a visitor who has already submitted.

This is what `/plan/{uuid}` renders and what the plan email links back to. Where 5a takes the
answers as input, this reads them from the lead the quiz created, so the visitor can return to
their report from a link without re-answering anything.

**A GET, and that does not contradict 5a's rule.** The reason `POST /protocol/preview` must
stay a POST is that its INPUT is a health inference — goals, sex and age in a query string get
written into every log between browser and origin. This endpoint's input is an opaque UUID,
which reveals nothing about the person; the answers travel only in a response body, which that
machinery does not log.

**The UUID is a bearer credential**, exactly as on `GET /leads/{uuid}`. Whoever holds it sees
the plan — it arrived in the visitor's own redirect and their own email. Treat the page as
private: `noindex, nofollow`, and never cache the response.

**Recomputed on every read, never snapshotted.** The plan reflects the catalogue as it stands
now, so a product withdrawn for safety stops being recommended to someone still holding the
link. The other half of that trade is real: editing the catalogue changes what an
already-issued link shows. Revisit this alongside a PDF, where the artefact and the page could
otherwise disagree about the same person.

`data` is **identical in shape to 5a** — same per-goal entries, same `outcome` taxonomy, same
`excluded_count` count-not-list rule. Both are produced by one `ProtocolPresenter`, so a new
field appears on both at once; do not write a consumer that handles one shape and not the other.

`meta` carries four things beyond 5a's `filtered`:

| key | meaning |
|---|---|
| `goal_count` | Goals resolved. **Zero is a real state**: they never took the quiz, or every goal they picked has since been withdrawn |
| `quiz_completed_at` | Null for a lead created at checkout. Separates "answered and matched nothing" from "never answered" — different sentences |
| `email_pending` | A plan email is genuinely still coming |
| `copy` | The operator's results-page copy, keyed by state |

**`email_pending` is not "did they consent".** All four of these must hold: they consented, an
address is present, nothing has been sent yet, **they completed a quiz**, and sending is actually
possible. The last clause consults the same gate the send listener does, so a page cannot promise
a delivery an operator has switched off or that would land in a log transport. The quiz clause is
the one that is easy to miss: the only sender is the plan-email listener on `QuizCompleted`, which
fires solely for a lead submitted WITH a quiz — so a lead created at checkout, which reaches this
endpoint through a recovery link, has no send coming no matter what it consented to. Render a
future delivery only when this flag is true. A page saying "we've sent it" on the strength of a
consent tick is lying with a green tick.

**Known gap:** a quiz lead whose send was SKIPPED while mail was disabled reads `email_pending`
true once mail is enabled — nothing retries, and nothing distinguishes "not sent yet" from "send
was skipped".

**`meta.copy` is the operator's, and the frontend never composes a sentence.** Keys map to the
state they belong to: `heading`, `intro` (shown ONLY when something matched, so it may promise
results), `restricted`, `unmapped`, and `empty` (no goals at all). Each is rich text and each
may be **null** when unauthored — render nothing rather than substituting a default, which would
put brand voice in a repo that ships to more than one brand.

**`restricted` and `unmapped` must never fall back to each other.** Telling someone who was
ruled out that "we're still building this" is false, and so is the reverse. The backend
distinguishes them against an unfiltered baseline because the frontend cannot.

**Zero matches is a designed outcome, not an error.** The eligibility gate means some visitors
legitimately match nothing.

## 6. Commerce flow

The active checkout path comes from `GET /config` → `checkout.path` (`prx` | `local`). Branch the whole flow on it — never assume one.

1. **Cart** — send `X-Cart-Token` (ULID) on every cart call; the backend mints one if absent (read it back from the response and persist client-side). `GET /cart`, `POST /cart/items` (`{type: product|package, id, plan_id?, quantity}`), `PATCH|DELETE /cart/items/{id}`.
2. **Upsells** — `GET /cart/suggestions` returns admin-curated Pairs With / Related light cards for the current cart (empty when the admin disabled upsells — just hide the placement). `config.checkout.upsells` carries the knobs. Products can be added directly (buy-once); link packages through to their page for plan selection.
3. **Lead** — `POST /leads` with customer identity + consents + UTM attribution; include `X-Cart-Token` to bind the cart. Returns a lead `uuid` **and `handoff_url`**.
4. **`prx` path (embed handoff — the default)** — after lead creation, redirect the browser to `lead.handoff_url`. That backend page hosts the provider embed with prefill + product selection already applied; clinical intake and payment happen there. Do **not** call `POST /checkout` on this path.
5. **`local` path** — `GET /checkout/gateway-config` for the tokenization SDK, then `POST /checkout` with `cart_ulid`, `lead_uuid`, and the tokenized `payment_method`. Order status afterwards: `GET /orders/{uuid}`.

## 7. Local development

- Point `API_BASE_URL` at the local backend vhost.
- If the backend uses an mkcert TLS cert, run Node with `NODE_OPTIONS=--use-system-ca` (mkcert installs its root CA into the OS trust store). This must be set in the shell/npm script — Node reads it at startup, so `.env.local` is too late. Do **not** disable TLS verification.
- A fresh backend has no CMS content: the config endpoint always answers, `/pages/home` 404s until the page is created in the admin. Build empty states accordingly.
- Neutral dev catalog: `php artisan db:seed --class=DevCatalogSeeder` (backend repo) seeds generic products/packages; `HomePageSeeder` seeds an **empty home-page scaffold** (8 standard section types, no content). Blueprint defaults are intentionally content-free, so nothing renders until content is authored in the admin (or loaded by a deployment-specific fill script kept in that deployment's frontend repo).

## 8. Hard rules for implementers

1. **No hardcoded branding.** Company name, logos, colors, copy, contact info, tracking IDs — all must come from the API. If you find yourself typing a brand string into a component, it belongs in the admin.
2. **Own your route patterns** for entity links; the backend only emits `{type, slug}`.
3. **Render unknown section types visibly in dev** (placeholder), silently skip in production — never crash on a new backend type. Either way, check `has_content` **first**: a section with none renders nothing, whether or not you have a component for its type. Empty scaffold sections may never leak onto a page.
4. **Never render an authored string as a text node.** Every operator-editable
   field is HTML — see 4a. Inline-kind fields go inside an element you choose;
   prose-kind fields get a container of their own.
5. **Respect `allow_indexing` and per-page `noindex`.**
6. **Keep API tokens server-side.**

## Catalog-only sections that read their record (2026-08-30)

Two section types serve a product or stack detail page and nothing else:

| type | renders |
|---|---|
| `item-faqs` | that record's published FAQs |
| `item-reviews` | that record's approved reviews, plus its rating |

Their `data` is presentation only — an optional inline `heading`. **The rows
are not in the payload's section data**; they are the record's own `faqs`,
`reviews` and `rating`, already served at the top level of the detail payload.
So the frontend passes the record into its section renderer and those two
components read it; every other type stays a self-contained envelope and
nothing is duplicated.

Both declare intrinsic content, so they arrive with `has_content: true` even
with a null heading. Render nothing when the record has no FAQs/reviews.

They are absent from the CMS page picker (`contexts: ['catalog']`) because a
page has no record for them to read — but that gates authoring only, so
anything already stored keeps resolving.
