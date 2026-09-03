# Catalog Module — Operator Guide

## Overview

The Catalog is where you manage the products, packages, subscription plans, categories, and tags that appear on your storefront. All catalog data is served to the React frontend via the API — nothing is hard-coded.

---

## Products

A **Product** is a single item that can be added to a cart independently (e.g., a single vial, a cream, a supplement).

**Key fields:**

| Field | Description |
|---|---|
| Name / Slug | Slug is the URL path (`/products/{slug}`). Auto-generated from name. |
| Subtitle | Short one-liner shown under the name in listing cards. |
| Short description | 1-2 sentences for listing pages and meta fallback. |
| Description | Full rich text for the product detail page. |
| Status | **Draft** = hidden. **Published** = live. **Archived** = discontinued. |
| Badge | Small label shown on listing cards ("Best Seller", "New", "Rx Required"). |
| Highlights | Bullet points for the detail page feature list. Add one per row. |
| Pricing | **Retail** = regular price. **Sale** = discounted price (shown if set). **Suffix** = text after price ("/vial", "/month"). |
| Hero image | Main product image. |
| Gallery | Additional images for the image carousel. |
| Featured | Surfaces product in "Featured" filtered views. |
| Requires lab | Shows a "Lab work required" badge on the detail page. |
| Provider product ID | The matching product UUID in PrescribeRx (or other configured provider). Required for checkout. |
| Provider SKU | Human-friendly product code from the provider. |
| Provider encounter type ID | Overrides the category-level encounter type for this specific product's intake form. Leave blank to inherit from category. |
| Classification | Product class (Peptides, HRT…), type, physical form (vial, troche…), and administration method (oral, sub-q injection…). Options are managed under Shop → Product Classes / Types / Forms / Administration Methods. |
| Volume + unit | Container size, e.g. `10 mg` for a lyophilized vial or `3 ml` for a liquid. |
| Inventory status | In Stock / Back Ordered / Out of Stock / Discontinued. When set, the In-stock flag is derived automatically (In Stock and Back Ordered count as purchasable). Leave empty to manage the flag by hand. |
| Prescription required | Mirrors the provider's Rx flag; kept current by sync. |
| Controlled substance | Compliance flag for reporting. |
| Cost | What the company pays — used for internal reporting/P&L only. **Never shown on the storefront or the public API.** |
| Detail page content | Reorderable content blocks for the detail page. Each block has a title, copy, and a placement: "Sidebar accordion" (e.g. How To Use) or "Description tab". |

**Related tabs on the product edit page:**

- **Ingredients** — attach ingredients with potency: concentration + unit
  (e.g. `50 mg`), and for liquids an optional per-volume denominator
  (`10 mg / 3 ml`). Leave per-volume blank for lyophilized/dry products.
  Ingredient rows themselves are managed under Shop → Ingredients.
- **Certificates of Analysis** — upload a COA per manufacturing batch
  (PDF or image, max 10 MB) with the batch number and issue date. Visible
  COAs are listed on the public product detail API.
- **Related & Pairs With** — link other products *or* stacks. "Related" =
  similar items; "Pairs With" = suggested companions for building a custom
  stack. Both power the corresponding sections on detail pages.

### Vocabulary lookups (Shop menu)

Product Classes, Product Types, Ingredients, Administration Methods, Product
Forms, and Measurement Units are all editable lists — rename, reorder,
deactivate, or add rows freely. A fresh install seeds standard clinical
vocabulary (16 administration methods, 25 forms, 10 units) matching PrescribeRx
terminology. The "Provider mapping" fields on each row tie it to the PRX
equivalent so synced products reuse your rows instead of creating duplicates —
leave them blank for vocabulary you add for other fulfillment sources.

### PRX matching & mapping

- **Shop → PRX Catalog** — browse the live PRX inventory. Unmapped rows can be
  **Imported** (creates a local Pending item linked to the PRX row) or
  **Mapped to an existing** local item. Mapped rows link straight to the local
  edit page. "Run full sync" imports everything and refreshes pricing/clinical
  data on all mapped rows (your curated names, descriptions, and images are
  never overwritten once an item leaves Pending).
- **Products / Packages lists** — the **Unmapped** tab shows local items not
  yet linked to PRX. Use **Match to PRX** on a row for ranked suggestions
  (best SKU/name matches are starred); **Clear PRX mapping** unlinks a row.

---

## Packages

A **Package** bundles one or more products and can be bought **two different ways**. The
difference is not cosmetic — it decides whether the customer is billed again:

| Shape | What the customer gets | Billed again? |
|---|---|---|
| The package at its **own price** | The bundle once — 1 of each product in it | **No** |
| The package **with a Plan** | The same bundle, rebilled monthly or prepaid over a term | **Yes** |

**Key fields** (same as Product, plus):

| Field | Description |
|---|---|
| Banner image | Wide hero banner for the package landing section. |
| Products | Assign which products are included in this package. Set sort order in admin. |
| Plans | Optional subscription tiers (see Plans section below). A package with no plans is still fully sellable at its own price. |

Both are real, sellable shapes, and the customer chooses between them on the package page.

### What a listing card shows: "As low as $X"

**Cards advertise the cheapest way in.** For each package the storefront compares its own
one-time price against its **monthly** plans and shows the lowest, labelled "as low as" — so
$399 one-time against a $279.99/mo plan gives a card reading **"As low as $279.99/mo"**. The
same rule runs on product cards.

**"As low as" is a floor, not a quote, which is why a card never adds a stack to the cart.** The
cheapest figure is usually a plan, and a plan is a recurring commitment — so the customer picks
the term on the package's own page, where the terms are visible. Anything that showed the low
figure and then added something on the customer's behalf would either sign them up to a rebill
they did not choose or charge more than the card said.

**Prepaid multi-month plans never become the card figure.** A $1,259.96 six-month plan is a
TOTAL, not a rate; only monthly plans are compared, so a card cannot advertise a prepay total as
though it were a monthly price. They still appear on the package page.

**Leave the package's price Suffix BLANK unless the price genuinely is per-unit.** The suffix is
free text and is printed exactly as typed — nothing validates it. A one-time bundle price is not
charged per period, so "/mo" there makes a card read "As low as $399.00/mo" for a purchase
billed once. Cadence wording belongs on plans, where the billing period fills it in
automatically.

**A package with no retail or sale price** is sold through its plans alone, and its cards read
"As low as $X/mo" from the cheapest of them.

---

## Plans

A **Plan** belongs to a Package **or a Product** (never both) and defines a
pricing tier and billing cadence. Products get the same Plans tab as
packages — add term plans (e.g. 3/6/12-month pricing) to sell a single
product on subscription; a plan with "Recurring / subscription" enabled IS
the subscription, no separate toggle needed. The product's own retail/sale
price remains the one-time "buy once" option shown alongside the plan grid.

**Plans are an addition, never a replacement.** For packages the same holds: the
package's own price is the one-time buy and stays what cards quote, and a plan
adds a recurring alternative on the package page. Deleting every plan leaves a
package perfectly sellable; clearing its own price does not.

**A suffix you type OVERRIDES the billing period, and nothing checks the two agree.** Left
blank, a quarterly plan gets "/qtr" and a six-month plan "/6mo" automatically. Type "/mo" on a
quarterly plan and its $3,050 term TOTAL reads "$3050/mo" — on the package page, and in the
term picker on the plan page — which tells a visitor they will be charged that every month.
Only type a suffix when the price genuinely is per that period.

**Example:** A "Testosterone" package might have three plans:
- Monthly — $299/mo, auto-renews
- 3-Month Supply — $799 (save 11%), recurring every 3 months
- 6-Month Supply — $1,399 (save 22%), recurring every 6 months

| Field | Description |
|---|---|
| Billing period | Monthly / Quarterly / 9-Month / Annual / One-time |
| Term (months) | Explicit month count sent to the provider at checkout (1, 3, 6, 9, 12). |
| Retail / Sale price | Display prices. Sale price shows as discounted if set. |
| Price suffix | Appended to price in the UI ("/mo", "every 3 months"). Auto-filled from billing period if blank — **leave it blank and it is always right.** |
| Badge | "Most Popular", "Best Value", etc. |
| Pre-selected | Mark one plan per package as the default selection on the package page. |
| Recurring | Toggle ON for subscription plans. OFF = one-time purchase. |
| Rebill strategy | **Auto-renew** = renews on schedule until cancelled. **Patient choice** = patient picks interval at checkout. |
| Trial days | Number of free trial days before first charge (0 = no trial). |
| Provider plan ID | Matching plan UUID in the configured provider. |
| Provider product IDs | JSON array of provider product UUIDs to pass at intake for this plan's line items. |

---

## Categories

Categories group products and packages for storefront navigation. They support one level of nesting (parent → children).

**Provider encounter type ID:** The most important field for clinical workflows. When set on a category, all products in that category will use this encounter type's intake form at checkout. Override at the product level when a specific product needs a different intake form.

---

## Tags

Tags are a flat taxonomy for cross-cutting filters (e.g., "Popular", "Testosterone", "Weight Loss"). Tags can be applied to products, packages, and plans.

---

## Product & stack FAQs

Every product and package edit page has a **FAQs** tab. Questions shown there
render on that item's storefront detail page, in the order you drag them into.

- **Attach** picks existing questions from the FAQ module (multi-select) — the
  same question can appear on any number of products and on the general FAQ
  page at once.
- **New FAQ** authors a question in place; it is saved into the FAQ module and
  attached to this item in one step.
- Unpublishing a question (here or in the FAQ module) removes it from every
  page it's attached to without losing the attachments.
- The drag order here only affects this item's page. The general FAQ page
  keeps its own category/position ordering.

---

## Per-product page building

Two tools control how much extra content a product or stack page carries —
use as much or as little per record as you want:

- **Page Sections tab** — inject full content sections below the product
  info: video embeds, image/text bands, testimonials, or any section type
  available to the page builder (including admin-defined flexible types).
  Drag to reorder. "Reuse a global block" attaches shared content that is
  edited once and updates everywhere it's used. Disabled sections stay
  saved but don't render.
- **Detail page layout** (on the record form) — presentation knobs: page
  template (Classic vs Conversion), accordion placement (side column vs
  full-width below), Pair With slider sizing, and which recommendation
  rails show at the bottom. Blank fields fall back to the deployment
  default.

---

## Product & stack reviews

Every product and package edit page has a **Reviews** tab for curating
customer reviews (star rating, author display name, optional title/body,
review date).

- Only **approved** reviews appear on the storefront or count toward the
  star rating shown there. Un-approving hides a review without deleting it.
- If an item has no approved reviews, the storefront shows no stars at all —
  ratings are never invented.
- Reviews collected through future channels (patient portal, external review
  platforms) will appear in this same tab for the same approve/hide
  moderation.

---

## Publishing checklist

Before setting a product or package to **Published**:
- [ ] Hero image uploaded
- [ ] Short description filled in
- [ ] At least one category assigned
- [ ] Provider product/package ID set (required for checkout to work)
- [ ] If a package: at least one Published plan with a price

---

## Display pricing vs transaction pricing

Prices in this admin are **display prices** — what customers see on the storefront. The actual transaction amount is determined by the provider (PrescribeRx) at checkout time based on the `provider_product_ids` and `provider_plan_id` you configure on each plan. If local checkout via NMI/Authorize.net is configured instead, the `sale_price ?? retail_price` is used as the transaction amount.

---

## Ingredient eligibility — who each substance may be offered to

**This is the safety gate the intake quiz applies before it ranks anything.** Relevance
weights (Health Goals → Recommends) order options that are *all acceptable*. Eligibility
decides which are acceptable at all. A weight of 100 on testosterone will not float it past a
female visitor — the gate runs first.

Edit it under **Shop → Ingredients → (an ingredient) → Eligibility**.

| Field | What it does |
|---|---|
| **Who can be offered this** | `Anyone` (default), `Male only`, `Female only` |
| **Minimum age** / **Maximum age** | Blank on either side means no bound in that direction |
| **Why — shown in the protocol** | Your sentence explaining the rules above. Quoted in the generated protocol and PDF |

### It is set on the ingredient, not the product — on purpose

A product inherits the rules of everything it contains. Mark Testosterone Cypionate as
*Male only* once, and every product containing it — including products you add next year —
is male-only automatically. There is no per-product checkbox to forget.

Two consequences worth knowing:

- **A combination product is as restrictive as its strictest ingredient.** A blend containing
  one male-only ingredient is a male-only product, whatever else is in it.
- **A stack is withheld if any product in it is unsuitable**, because a customer buys a stack
  whole and cannot decline one item in it.

### Everything defaults to "Anyone", and that is deliberate

A new ingredient is offered to everyone until you say otherwise. The failure direction is
chosen: an unset field **over**-offers a safe substance rather than **under**-offering it,
because you will notice a product that should not have appeared and you will never notice one
that silently stopped appearing.

That means **classification is a job you have to do**, not something the system infers. The
Ingredients list shows an **Offered to** column and an **Age** column precisely so you can see
at a glance which substances nobody has classified yet — an unclassified male-only ingredient
looks exactly like a correctly unisex one until you set it.

### Blank age is not 18

Leaving **Minimum age** blank means "no lower bound", not "18 and over". If a substance really
does have a floor, type it. A bound nobody set must never start filtering people out.

### What a visitor sees when nothing is suitable

If every ingredient for a goal is filtered out, the visitor is told honestly that you do not
currently stock something appropriate for them, and is pointed at a clinician. They are **not**
shown an empty page, and they are not shown something unrelated instead.

This is worth checking after you classify: if your catalogue has no female-indicated
ingredients mapped to a goal, every woman choosing that goal reaches that message. That is the
correct behaviour, and it is also a signal to stock the other side of the shelf.

### Sex here means physiological applicability

The field records what a substance is clinically appropriate for, not how a person describes
themselves. The wording the visitor actually reads in the quiz is separate authored copy — you
can change the question without touching any ingredient.

An answer the system does not recognise as male or female filters **nothing** rather than being
guessed into a bucket. Someone who self-describes sees the full range rather than a narrowed
set that a string comparison picked for them.

## Renaming a product, package or page — what happens to the old link

**You can rename freely. The old address keeps working.**

When you change a slug, the site remembers the previous one and sends anyone
arriving at the old address straight to the new one. Links you have already
shared, printed, bought ads for, or that Google has indexed all keep working —
visitors land on the right page and search engines learn the new address.

Menus, buttons and product cards always use the current slug. You never need to
go and update them.

**Rename as often as you like.** Rename `a` to `b`, then later to `c`, and
*both* older addresses go straight to `c` — never through a chain of hops.

**If you give a record back an old name, it simply takes it back.** The
redirect for that name disappears, because the name is in use again.

**One thing to know:** if you *delete* a record, its old addresses stop
redirecting and go back to showing "not found". Sending someone to a page that
has itself been deleted would just move the dead end.

## Filtering by health goal

Shoppers can now filter products and stacks by **health goal** — the same goals
the quiz uses. Nothing to set up: any goal already assigned to a product shows
up as a filter option automatically, with a count beside it.

A goal only appears as a filter when it has at least one **published** product
behind it. A filter that leads to an empty page is worse than no filter, so
empty goals are hidden rather than shown as a dead end.

**Goals and categories are different things, and it is worth keeping them
different.** A goal says what a product is *for* (weight management, sleep and
recovery). A category says how you *merchandise* it (GLP-1, peptides, HRT). If
you create categories that repeat the goals, you will be maintaining the same
information twice, and the two will disagree the first time someone edits one.

**Renaming a goal is safe** — links using the old name keep working.

## What happens when you delete a product or stack

Deleting moves it to the bin. Everything about it is kept — its goals,
ingredients, categories, tags, FAQs and reviews — so **restoring it brings it
back complete**.

Permanently deleting it removes all of that with it, and cannot be undone.
