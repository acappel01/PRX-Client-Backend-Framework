# Catalog Module — Developer Guide

**Status:** Shipped 2026-06-22 · Clinical schema expansion + PRX mapping admin shipped 2026-08-16

## Data model

```
categories (tree, polymorphic pivot via categorizables)
tags       (flat, polymorphic pivot via taggables)
products   ← belongsToMany → packages  (via package_product)
products   ← belongsToMany → ingredients (via ingredient_product, potency pivot)
products   → hasMany → product_coas
products   → belongsTo → product_classes / product_types / product_forms /
             administration_methods / measurement_units (volume_unit_id)
packages   → hasMany → plans
products   → hasMany → plans   (term plans — package_id XOR product_id, model-guarded)
products & packages → morphMany → catalog_relations (source) → morphTo related
```

All catalog items share `CatalogStatus` (Pending / Draft / Published / Archived) and `SortableTrait` (`position` column). Only Published items are served via the public API.

### Lookup tables (admin-managed vocabulary)

Six lookup modules under **Shop** in Filament. All are local rows (NOT enums) so
non-PRX fulfillment deployments can manage their own vocabulary; each carries a
provider mapping column so PRX syncs land with matching terminology:

| Table | Provider mapping | Seeded by |
|---|---|---|
| `product_classes` | `provider_product_class_id` (uuid) | PRX sync (`/product-classes` or embedded objects) |
| `product_types` | `provider_product_type_id` (uuid), `product_class_id` FK | PRX sync |
| `ingredients` | `provider_ingredient_id` (uuid) | PRX sync (product detail payload) |
| `administration_methods` | `provider_value` (PRX `ProductDeliveryMethod` int) | `CatalogVocabularySeeder` (16 rows) |
| `product_forms` | `provider_value` (PRX `ProductForm` int) + `requires_volume` flag | `CatalogVocabularySeeder` (25 rows) |
| `measurement_units` | `provider_value` (PRX `UnitsOfMeasure` int) | `CatalogVocabularySeeder` (10 rows) |

`CatalogVocabularySeeder` runs from `DatabaseSeeder`, is idempotent (slug/abbr
match), and never overwrites admin-edited names.

### Key columns

| Table | Notable columns |
|---|---|
| `products` | `provider_product_id`, `provider_product_sku`, `provider_encounter_type_id`, `highlights` (json), `detail_sections` (json), `badge_text`, classification FKs (`product_class_id`, `product_type_id`, `product_form_id`, `administration_method_id`), `volume` dec(10,4) + `volume_unit_id`, `inventory_status` (enum, drives `is_in_stock` via saving hook — InStock/BackOrdered = purchasable), `is_controlled_substance`, `rx_required`, `cost` dec(10,2) **internal-only** |
| `packages` | same provider columns, `banner_image_path`, `highlights` (json), `detail_sections` (json), `badge_text`, `cost` |
| `plans` | `billing_period` (enum), `billing_mode` (enum, mirrors PRX BillingMode: prepaid_term/recurring/installment/external), `term_months` (int), `is_recurring`, `rebill_strategy` (enum), `trial_days`, `is_default`, `intro_price`, `cost`, `provider_product_ids` (json) |
| `categories` | `provider_encounter_type_id` — maps a category to a PRX encounter type for dynamic intake |
| `package_product` | `sort_order`, `is_included` (true = bundled, false = optional add-on) |
| `ingredient_product` | `concentration` dec(10,4) + `concentration_unit_id`, optional `per_volume` dec(10,4) + `per_volume_unit_id` (nullable for lyophilized), `provider_quantity_label` (raw PRX string), `position`. Custom pivot `IngredientProduct` with `potencyLabel()` → "50 mg" / "10 mg / 3 ml" |
| `product_coas` | `batch_number` (unique per product), `file_path` (PDF or image on public disk, `catalog/coas/`), `file_type` (derived on save), `issued_at`, `is_visible`, `created_by` |
| `catalog_relations` | double-polymorphic `source` + `related` morphs (Product/Package both sides), `relation_type` (`related` \| `pairs_with`), `position`. `HasCatalogRelations` trait → `relatedItems()` / `pairsWithItems()` (published only) |

**`cost` is internal P&L data and must never be serialized by any public API
resource** — regression-tested in `ProductClassificationTest`.

### Highlights format

The Filament Repeater stores `[{"item": "...", "icon": "ti ti-truck"}]`; the API
normalizes it to **`[{text, icon}]`**, `icon` being a Tabler class or `null`.

THREE STORED SHAPES ARE LIVE and `NormalizesHighlights` tolerates all of them:
the current `{item, icon}`, an older `{item}` alone, and a bare string written
by the fill scripts. The original implementation was a plain `pluck('item')`,
which returns null for a string entry and then filtered it away — so
`performance-stack` held four highlights and served `[]`, indistinguishable
from an operator who had written none.

The icon arrived when highlights became the **credibility list in the buy box**
("Physician-supervised", "Licensed pharmacy") — an icon list needs an icon per
row. Rows authored before the field carry `icon: null` and the frontend falls
back to a check mark, so adding it touched no records.

**Highlights are NOT the benefits diagram.** They used to be: a hardcoded
diagram rendered on the detail page whenever this field was non-empty, so
adding one line made a large radial block appear and the only way to remove it
was to delete the line. The diagram is now the `benefits-diagram` SECTION, added
under Page Sections like anything else. Do not re-couple them.

### Provider encounter type resolution (at intake time)

Priority order when determining which encounter type to use:
1. `products.provider_encounter_type_id` (product-level override)
2. `categories.provider_encounter_type_id` of the product's primary category
3. Fallback: prompt user to pick from active encounter types

---

## API endpoints

All catalog endpoints are **public** (no auth required). Rate-limited to 120 req/min per IP.

### `GET /api/v1/catalog/categories`

Returns all visible categories ordered by `position`.

| Param | Type | Description |
|---|---|---|
| `tree` | bool | Embed children. Returns top-level only when true. |

```json
{
  "data": [
    {
      "id": 1,
      "name": "Hormone Therapy",
      "slug": "hormone-therapy",
      "provider_encounter_type_id": "hrt-basic",
      "children": [...]
    }
  ]
}
```

### `GET /api/v1/catalog/categories/{slug}`

Single category with `parent` and `children`.

### `GET /api/v1/catalog/products`

Paginated. 15 per page, max 50.

| Param | Type | Description |
|---|---|---|
| `category` | string | Filter by category slug |
| `tag` | string | Filter by tag slug |
| `class` | string | Filter by product class slug |
| `type` | string | Filter by product type slug |
| `form` | string | Filter by product form slug |
| `ingredient` | string | Filter by ingredient (compound) slug |
| `featured` | bool | Featured products only |
| `in_stock` | bool | In-stock products only |
| `price_min` / `price_max` | float | Bounds on `price_from.amount` — the figure the card shows |
| `search` | string | Name / subtitle LIKE search |
| `sort` | string | `position` (default) \| `name` \| `-name` \| `price` \| `-price` \| `newest` \| `oldest` — whitelisted in `SortsCatalogQueries`. Price sorts on `price_from.amount` for both kinds, so the order matches the cards |
| `per_page` | int | Page size (max 50) |

Response includes `links` + `meta` pagination keys. Cards carry a
`classification` block (`class`/`type`/`form`/`administration_method`, each
`{id, name, slug}` or null), `volume {value, unit}`, `inventory_status`,
`rx_required`, `is_controlled_substance`.

### `GET /api/v1/catalog/products/{slug}`

Full product detail. Adds over the card shape: `seo`, `ingredients`
(`[{name, slug, concentration, per_volume, label}]` — `label` is the composed
potency e.g. `"10 mg / 3 ml"`), `coas` (visible only —
`[{batch_number, file_url, file_type, issued_at}]`), `detail_sections`
(`[{title, placement: accordion|tab, content}]`), `related` and `pairs_with`
(published-only light cards with `type: product|package` for routing), and
`plans` (published product term plans, position-ordered — same PlanResource
shape as package plans; drives the detail-page deal grid, with the product's
own `price` as the one-time/buy-once option). A plan belongs to a package OR
a product, never both.

**Relation light cards price a package by ITSELF**, and carry a `price_range`
alongside `price`. They used to substitute the package with its default plan,
which was correct only while packages had no price columns of their own; once
they did, a $399 buy-once stack advertised its subscription's price on every
upsell and pairs-with card. The range spans the package's plans and its own
price, so a stack sold only through plans — most of them — can still be shown
as "From $X" rather than blank. `price.effective` is `null` (never `0.00`)
when unpriced, and `price_range` is `null` for products (a product's own price
is the whole story on a rail card).
Also includes `faqs` — see [Polymorphic FAQs](#polymorphic-faqs).

### `GET /api/v1/catalog/packages`

Same filter params as products (minus class/type/form/ingredient) plus `sort`. Each package
includes its Published plans, a `price_range` spanning the plans and its own price, and
`price_from` — the figure a card leads with.

**`price_min` / `price_max` and `sort=price` operate on `price_from.amount`, not on plan
prices and not on the package's own columns.** A package's card shows the cheapest way in
(its own one-time price against its monthly-cadence plans), and a filter measuring anything
else contradicts the numbers on screen. It previously ran `whereHas('plans')` on plan prices,
which made the package's own price invisible to the filter and left a package with **no**
plans unable to match any price range at all — cards read "As low as $399.00" and vanished at
a $350 minimum because their plans were $279.99 and $671.98 with nothing in between.

The figure is not a stored column, so filtering, sorting and aggregating it need it as SQL:
`HasCardPriceExpression` is the single definition, exposed as `Package::priceFromAmountSql()`
and `Product::priceFromAmountSql()` and used by both listings' filters, both price sorts, both
facet bounds, and the quiz's option figures. **Products run the same rule for the same reason**
— a product's own price is its card figure only while no product carries a monthly plan, and
that is a coincidence rather than a rule. It mirrors the **amount** half of
`BuildsCatalogPricing::catalogPriceFrom()` — the suffix, the `plan_id` and the non-recurring
tie-break decide which candidate is reported, never what the lowest number is.
`CatalogPriceParityTest` asserts the two agree on every branch, for both kinds, including the ones raw SQL
gets wrong for free: soft-deleted plans (the relation hides them, SQL does not), unpublished
plans, unpriced plans, and intro prices.

### `GET /api/v1/catalog/packages/{slug}`

Full package detail. Includes `products` (bundled items), `plans` (subscription tiers), `detail_sections`, `related`, `pairs_with`, `faqs`. Plans include a `billing` sub-object with `term_months`, `is_recurring`, `rebill_strategy`, `trial_days`, and `mode`/`mode_label` (billing mode).

### Polymorphic FAQs

Products and packages expose `faqs` on their **show** endpoints only:
`[{id, question, answer, category}]` (`category` is the FAQ category name or
`null`). Items come from the Content module's `faq_items` table via the
`faqables` morph pivot (`App\Models\Concerns\HasFaqs` on Product/Package;
inverse `products()`/`packages()` on `FaqItem`).

- **Ordering** is per-attachment: `faqables.position` (drag-reorder in the
  admin), NOT `faq_items.position` (which orders the general FAQ page).
  Both tables have a `position` column — always qualify the pivot column.
- **Visibility**: only `is_published` items are returned; unpublished items
  stay attached but never render. Attachment does not affect the general
  `/api/v1/faqs` endpoint — the same item can serve both.
- **Admin**: shared `FaqsRelationManager`
  (`app/Filament/Resources/Catalog/Products/RelationManagers/`, wired into
  both ProductResource and PackageResource) — attach existing items
  (multi-select) or author a new one in place ("New FAQ" creates the
  FaqItem and attaches it).

### Injectable sections + detail_layout (per-record page building)

Products and packages expose on their **show** endpoints:

- `sections` — ordered list of CMS **section envelopes**
  (`{type, origin, anchor, global, data, schema?}`), the exact contract
  `/api/v1/pages` uses, so the frontend `SectionRenderer` consumes them
  unchanged. Backing: `catalog_item_sections` morph table
  (`App\Models\Catalog\CatalogItemSection` — PageSection's morph-attached
  sibling; `HasItemSections::sections()` on Product/Package). Serialized by
  the same `SectionDataTransformer` (media resolution, catalog inlining,
  SVG sanitizing, global-block indirection all apply). Every registered
  section type — code blueprints (video-embed, image-text-split, …) AND
  admin-defined flexible types — is available per record. Global blocks
  compose identically to pages: attach one block to many records, edit
  once. Disabled sections and unresolvable types are skipped.
- `detail_layout` — nullable per-record presentation JSON, served verbatim to
  the frontend's `normalizePresentation`:
  `{template: classic|conversion, accordions: {placement: side|below},
  pair_with: {desktop: 1–4, mobile: 1–2}, rails: [related|stacks|associated|none]}`.
  Every key optional; missing = deployment default. Never invent keys
  backend-side — the frontend normalizer owns defaults.

  **Pruned on WRITE, not on read.** `App\Support\DetailLayout::prune()` runs in
  all four catalog create/update actions and strips nulls, empty strings and
  empty arrays, plus any group left empty. This is load-bearing, not tidiness:
  Filament hydrates an untouched Select as `null` and an untouched CheckboxList
  as `[]` and dehydrates them the same way, so without pruning, saving a
  product *without opening the Layout tab* wrote a full object of nulls
  including `rails: []`. An operator fixing a typo in a subtitle would have
  silently deleted that page's recommendation rails. Pruning is what keeps
  "never configured" expressible after a save, and keeps the form's own
  promise — blank means the deployment default — true.

  **Consequently `rails: []` cannot mean "no rails"** — it is indistinguishable
  from a control nobody touched, and is pruned away. "Show no rails" is the
  explicit `none` token, the same idiom the section spacing scale uses, where
  `none` is deliberately not redundant with leaving a knob unset. `none` beats
  any rail selected alongside it.

  Pinned by `tests/Feature/Filament/DetailLayoutPersistsTest.php`, which mounts
  the real Filament pages — the original defect (the DTO carrying no
  `detail_layout` at all, so every Layout select saved successfully and wrote
  nothing) lived in the form-to-DTO seam and no action-level test could see it.

Admin: shared `SectionsRelationManager` ("Page Sections" tab, drag-ordered,
same form builder as the page builder — the statePath/`$get('type')`
gotchas from feedback-filament-group-get-paths apply) plus a "Detail page
layout" section on the record form (dotted `detail_layout.*` field names,
no statePath). Catalog show endpoints are not CmsCache-cached, so no
observer wiring is needed.

### Reviews (base module)

Products and packages expose on their **show** endpoints only:

- `rating` — `{average, count}` across approved reviews, or `null` when none
  (the frontend must render nothing, never a zero-count rating).
- `reviews` — approved only, newest `reviewed_at` first:
  `[{id, rating, author_name, title, body, reviewed_at}]`.

Backing: polymorphic `reviews` table (`App\Models\Content\Review`,
`HasReviews` concern on Product/Package with `approvedReviews()` +
`ratingSummary()`). Deliberately thin — rows are admin-curated today
(`source` = `admin`); the patient portal and per-client external review
integrations are expected to write into the same table with their own
`source` values, and the moderation flow (`is_approved`) stays identical.
Admin: shared `ReviewsRelationManager` on both catalog resources
(author-in-place, approve toggle, ternary approved filter).

### `GET /api/v1/catalog/tags`

All visible tags ordered by position.

### `GET /api/v1/catalog/facets`

Filter-sidebar payload: `categories` / `classes` / `types` / `forms` /
`ingredients` / `tags` (each `[{name, slug, count}]`, published-product counts,
zero-count rows omitted), `availability {in_stock, out_of_stock}` counts, and
**two** price blocks:

| Key | Spans |
|---|---|
| `price {min, max, currency}` | `price_from.amount` across published **products** |
| `package_price {min, max, currency}` | `price_from.amount` across published **packages** |

Both measure the figure the cards show, through the one shared expression
(`HasCardPriceExpression`), so a slider's ends, the rows it keeps and the order they appear in
cannot disagree with each other or with the cards.

**Two blocks because one endpoint serves both listings, and the two catalogs do not span the
same prices.** Both measure the same thing — the card figure — but over different rows, so a
slider fed the wrong block labels one catalog's range while filtering the other's. That is what
`/stacks` did until `package_price` existed. `package_price` is
additive: `price` has always meant products and other frontends read it.

**Known and not fixed: every other facet group is still product-scoped.** The counts are
published-PRODUCT counts, and `products_count > 0` drops a category or tag attached only to
packages from the payload entirely — so it cannot be selected on the package listing even though
`PackageController` honours those same slugs. `availability` is likewise a product count. Fixing
it needs a scoping parameter and a decision about what the counts mean per kind; it is recorded
rather than half-done.

---

## Controllers

| Controller | File |
|---|---|
| ProductController | `app/Http/Controllers/Api/V1/Catalog/ProductController.php` |
| PackageController | `app/Http/Controllers/Api/V1/Catalog/PackageController.php` |
| CategoryController | `app/Http/Controllers/Api/V1/Catalog/CategoryController.php` |
| TagController | `app/Http/Controllers/Api/V1/Catalog/TagController.php` |

All extend `ApiController`. List endpoints return `ResourceClass::collection()` (Laravel auto-adds pagination links when passed a paginator). Show endpoints return `$this->success($resource->toArray(request()))`.

## Resources

| Resource | File |
|---|---|
| ProductResource | `app/Http/Resources/Api/V1/Catalog/ProductResource.php` |
| PackageResource | `app/Http/Resources/Api/V1/Catalog/PackageResource.php` |
| PlanResource | `app/Http/Resources/Api/V1/Catalog/PlanResource.php` |
| CategoryResource | `app/Http/Resources/Api/V1/Catalog/CategoryResource.php` |
| TagResource | `app/Http/Resources/Api/V1/Catalog/TagResource.php` |

`seo` is gated with `$this->when($request->routeIs('...show'), ...)` — only included on detail pages, not list responses.

## PRX sync & mapping

### `SyncPrescribeRxCatalogAction` (`app/Actions/Catalog/`)

Triggered from ListProducts/ListPackages header actions, the PRX Catalog page,
or `php artisan prescribe-rx:sync-catalog`. Per run:

1. Upserts `product_classes`/`product_types` from `/product-classes` +
   `/product-types` (tolerates 404 on older PRX deployments — the embedded
   `product_class`/`product_type` objects on each product cover the gap).
2. Products/packages/plans matched by `provider_*_id`.
   - **Pending rows**: everything updated (name, descriptions, flags).
   - **Curated rows** (Draft/Published/Archived): marketing content preserved.
   - **Provider-truth fields update on EVERY sync regardless of status**:
     classification FKs, `rx_required`, `pricing.cost`, ingredients, plan
     `billing_mode`, pricing, SKU, fulfillment center.
3. Ingredients come from the product detail payload (`GET /products/{id}` when
   the list omits them). Upsert by provider uuid with case-insensitive name
   fallback (backfills the provider id onto admin-created rows). Quantity
   strings (`"50mg"`, `"10 mg / 3 ml"`) are parsed into the potency pivot;
   the raw string is always kept in `provider_quantity_label`.

### Mapping admin

- **PRX Catalog page** (`app/Filament/Pages/PrxCatalog.php`, Shop → PRX
  Catalog): Filament custom-data table over `RemoteCatalog`
  (`app/Services/PrescribeRx/RemoteCatalog.php`, 15-min cache). Kind filter
  (products/packages), search, mapped/unmapped badges. Row actions: **Import**
  (Pending mapped shell via `ImportPrxCatalogItemAction`; enriched on next full
  sync), **Map to existing** (unmapped local rows only,
  `MapProviderCatalogItemAction`), **Open local**. Header: refresh cache, run
  full sync.
- **Products/Packages tables**: `MatchToPrxTableAction` — suggestion-ranked
  select (exact SKU = 100, else `similar_text` name score; ≥60% top match
  preselected) + "Clear PRX mapping". List pages have **Unmapped** review tabs.

## Tests

`tests/Feature/Api/V1/Catalog/` — endpoint coverage: status filtering, category/tag/featured/search filters, class/type/form/ingredient filters, sort whitelist, facets, highlights normalization, price_range computation, per_page cap, plan billing fields, package-product relationship, classification/ingredients/COA/detail_sections exposure, cost-never-leaked regression, related/pairs_with published-only.
`tests/Feature/Catalog/` — `SyncPrescribeRxCatalogTest` (mocked Client: classification upsert, ingredient parsing, curated-content preservation, endpoint-404 tolerance), `PrxMappingTest` (suggestion scoring, map/unmap, import shells, page render), `ProductActionFieldsTest` (DTO→Action full field round-trip regression).

---

## Sex & age eligibility

Added 2026-08-28. The gate the recommendation chain applies **before** ranking.

### Schema

`ingredients` gains four columns (`2026_08_28_090000_add_eligibility_to_ingredients_table`):

| Column | Type | Notes |
|---|---|---|
| `sex_eligibility` | `string(16)`, default `any`, indexed | Cast to `App\Enums\Catalog\SexEligibility` (`any\|male\|female`) |
| `min_age` / `max_age` | `unsignedTinyInteger` nullable | Null = unbounded on that side. **Null is not 18** |
| `eligibility_note` | `text` nullable | Operator-authored rationale, quoted in the protocol/PDF |

`leads` gains `age` (`unsignedTinyInteger`, nullable). It coexists with `date_of_birth` rather
than replacing it: the quiz asks an age, a clinical intake captures a birth date, and
back-computing one from the other would fabricate a birthday nobody gave us.
`Lead::effectiveAge()` encodes the precedence (`date_of_birth` wins).

### Why the ingredient and not the product

The same argument the health-goals migration makes for recommendations. An ingredient is what a
product *contains*, and one ingredient backs several SKUs. Stated on the substance the rule is
written once and inherited by products that do not exist yet; stated on the product it is
restated per SKU and drifts the first time a new testosterone item ships with the flag
forgotten.

Measured before choosing: 10 of 11 products had ingredients attached. The eleventh was
`testosterone-cypionate`, whose pivot row was simply missing — a data gap, not a case against.

**There is deliberately no product-level override column** for eligibility. A second place to
state one clinical fact fails silently when the two disagree.

(`health_goal_product` is no longer empty — it is now also the edge that drives health-goal
**badges**, which is a display concern and not an eligibility one. See
[Health-goal badges](#health-goal-badges) below.) If a combination product ever needs looser eligibility than its strictest ingredient,
that is one migration adding an explicit nullable column where null keeps meaning "derive".

### Resolution

`App\Services\Recommendations\GoalRecommendationResolver`, with
`VisitorProfile(?sex, ?age)` as the input.

| Method | Reading | Use |
|---|---|---|
| `ingredientsFor` | Eligible only, ranked `is_first_line` then `relevance_weight` | The first hop |
| `productsFor` | Permissive — surfaces on ANY eligible ingredient | Browsing surfaces |
| `strictProductsFor` | Conservative — EVERY ingredient must pass | Anything reading as advice |
| `productIsSafe` | Safety only, goal-independent | Stack membership |
| `packagesFor` | ≥1 relevant product, ALL products safe | Stacks |
| `resolve` | All of the above plus `mapped_count`, `excluded_count` and `outcome` | The endpoint |

Three rules that are load-bearing:

- **A null answer is permissive.** `null` means "not asked", not "answered nothing". A visitor
  who never took the quiz sees the whole shelf. Narrowing on an absent answer would hide
  products from people who told us nothing, and nobody would notice.
- **A product with no ingredients is ineligible, not unrestricted.** It cannot be reached
  through the chain anyway, so saying it costs nothing and closes the bypass.
- **Safety ≠ relevance.** A stack is judged relevant by one product and safe by all of them.
  Conflating the two rejected every package in the catalogue — see the regression test.

### Endpoint

`POST /api/v1/protocol/preview` — `{goals: string[], sex?: string, age?: int}`.

**POST, not GET, deliberately.** A GET would write
`?goal=sexual-wellness&sex=male&age=62` into every access and proxy log — a health inference
about an IP. The response is per-visitor and uncacheable, so GET buys nothing either.

**Stores nothing.** Answers become a record only at lead submission, a separate consented step.

Each goal returns an `outcome` naming the three states the funnel must tell apart:

| `outcome` | Meaning | Frontend copy |
|---|---|---|
| `matched` | We have something | The products/stacks |
| `restricted` | We had something; not for this visitor | "we don't currently stock something appropriate" |
| `unmapped` | Nobody has built this goal out | "we're still building out our options" |

**`outcome` cannot be derived from `excluded_count`.** That counts INGREDIENT-level exclusions
only, and a goal can restrict at the PRODUCT level instead: map a unisex ingredient A, stock one
product holding both A and male-only B, and a female visitor gets an eligible ingredient, an
`excluded_count` of 0, and no products. So `resolve()` compares against an **unfiltered
baseline** — what this goal would offer someone we know nothing about. Empty baseline means
nobody built it (`unmapped`); non-empty baseline with an empty result means this visitor was
filtered out (`restricted`). The extra resolve runs only when the result is already empty.

`excluded_count` is a **count, not a list** — returning the names would let anyone enumerate
which substances are gated by varying the request body.

### Tests

`tests/Feature/Recommendations/` — 17 tests.
`GoalRecommendationResolverTest` covers the gate; `ProtocolPreviewEndpointTest` covers the HTTP
layer and exists because the resolver tests all passed while the endpoint 500'd: the resolver
returned `collect()` (a `Support\Collection`, no `->loadMissing()`) from its empty paths, so the
controller threw on the first genuinely `restricted` result — the exact outcome the feature was
built to produce. Frontend smoke check: `atlas-protocol-web/scripts/quiz-flow-check.mjs`.

## Health-goal badges

The chips a storefront shows on a catalog item — "weight loss", "sleep &
recovery", what the thing is *good for*. Served as `health_goals[]` on
`ProductResource`, `PackageResource` and `CatalogRelationItemResource`:

```json
"health_goals": [
  { "name": "Weight Management", "slug": "weight-management", "badge_color": "moss" }
]
```

**One vocabulary, deliberately.** These are the same `health_goals` rows the
quiz asks about, reached through the existing `health_goal_product` pivot. A
second tag table would let the quiz and the storefront name the same goal
differently, which is the one outcome worth ruling out. The consequence is that
the pivot now has two jobs — it was built as a recommendation *override* — and
`GoalRecommendationResolver` still resolves through `health_goal_ingredient`
and does not read it. **If the resolver ever starts reading that edge, every
badge becomes a recommendation override**; split them with a pivot flag before
that lands, not after. Noted on `HealthGoal::products()`.

### Colour

`health_goals.badge_color` holds a palette **name**, never a hex — the frontend
resolves it through `--palette-{name}`, so retuning a colour in the admin moves
every badge using it. The pre-existing `health_goals.color` hex column is a
different thing and is left alone; it is already on the public API surface and
other prx-backend frontends may read it.

**There is no badge text-colour column, and that is a decision.** The label
derives from `--palette-{name}-contrast`, the black-or-white companion the
frontend computes from the same hex, exactly as `style_button_color` does — so
a badge cannot be authored unreadable. The cost, weighed and accepted: brand
pairings like dark-green-on-pale-green are not expressible. Adding a picker is
one nullable column, one Select and one guard key.

`PaletteUsage::find()` knows about badges, so deleting or renaming a palette
colour a badge uses is blocked like any section knob. It is a plain column
query rather than the JSON walk, and it deliberately sits *outside*
`PaletteUsage::KEYS` — that list is pinned as a subset of `LayoutFields::KEYS`
and a catalog column would break the test that pins it. Soft-deleted goals do
not block.

### Packages: derived, with an override

A package shows the **union of the health goals of the products inside it**, so
tagging a product once updates every stack containing it and a stack cannot
claim a goal its contents do not treat. `health_goal_package` exists for the
one case derivation cannot express — a stack *marketed* for a single goal — and
when it has rows they **replace** the derived set rather than adding to it.
Display only; the resolver never reads it (packages are not mapped directly to
goals for recommendation).

**Derivation reads `Package::healthGoalSourceProducts()`, never `products()`,
and this matters.** `PackageResource` serializes `whenLoaded('products')`, so
eager-loading `products` on the listing purely to derive badges switched on a
full nested `ProductResource` payload for every package in the index — a public
contract change nobody asked for. `products()` also carries no status
constraint, so a draft product inside a published stack would have leaked and
would have badged a card whose own detail page showed no such badge. The
dedicated relation is published-only and is not serialized. Pinned by
`HealthGoalBadgeTest::test_the_package_listing_does_not_embed_its_products`
and `..._an_unpublished_product_does_not_badge_its_package`.

Every path that derives must eager-load `healthGoalSourceProducts.healthGoals`
plus `healthGoals`: both `PackageController` actions, `HasCatalogRelations`,
`CatalogInliner` (CMS sliders) and `ProtocolPreviewController` (quiz results).

**Anywhere the nested `products` array is also serialized — the show route,
the CMS sliders, the quiz — load `products.healthGoals` as well.** It is not
redundant with the line above: `healthGoalSourceProducts` and `products` are
separate relations hydrating separate model instances, so loading one leaves
the other's `relationLoaded()` false. That sibling load is what puts badges on
each product inside a stack, which is what the "What's Included" rows render.

A missing load yields `[]`, which is indistinguishable from "untagged" — no
test fails, nothing errors, the badges are simply absent. That is precisely how
the show route lost its per-product badges once already, past a green suite.
`HealthGoalBadgeTest::test_the_products_inside_a_package_carry_their_own_badges`
is the compensating control; keep one like it for any new surface.

### Where badges are deliberately absent

**Cart lines carry no badges.** `CartItemResource` wraps the itemable in a full
`ProductResource`/`PackageResource`, and the cart controllers load
`items.itemable` bare — so cart payloads serve `health_goals: []` (and, for
package lines, omit `price_range` and `products` entirely, their `whenLoaded`
gates staying shut). That is a decision, not the same forgotten-load accident
twice over: badges answer "is this for me?", which is a question asked while
browsing, not after the thing is already in the basket. If the cart drawer ever
should badge its lines, add the eager loads there — do not assume the empty
array means the feature is broken.

### Authoring

Products: a **Health goals** multi-select on the product form's Merchandising
tab, or the "Pinned products" relation manager on the goal itself. Packages:
the **Badge override** relation manager on the package, empty by default.
Colour: the **Badge colour** select on the health goal.

## Listing filters: health goals are the populated axis

`GET /catalog/products` and `/catalog/packages` accept `goal={slug}` alongside
`category`, `tag`, `class`, `type`, `form` and `ingredient`. `GET /catalog/facets`
returns a `goals` block first, before `categories`.

**Goals lead because they are the classification that actually has data.** On a
typical install every published product carries health goals — they are the
same vocabulary the quiz matches on — while categories are a merchandising axis
an operator fills in per deployment. Measured on one deployment: 38 goal links
across 13 of 14 live products, against **zero** category links on any live
product, which left the Category filter group rendering nothing at all.

The two axes are independent and should stay that way:

| | Means | Populated by |
|---|---|---|
| `goal` | what the product is *for* | shared with the quiz |
| `category` | how it is *merchandised* (`glp-1`, `peptides`, `hrt`) | the operator |

Modelling categories as goal synonyms (`weight-loss` beside `weight-management`)
produces two vocabularies for one idea that drift the first time either is
edited. Keep categories to axes the goals cannot express.

**`show_in_quiz` is not consulted by the facet.** It decides whether a goal is
*offered* in the quiz, not whether it classifies the catalog. `is_active` is the
gate here, and goals with no published products are omitted — a facet option
that leads to an empty page is worse than no option.

**A renamed goal redirects.** `HealthGoal` carries `HasSlugHistory`, and
`health_goal` is registered in `SlugRedirectController`, so `?goal=old-slug`
resolves to the current one. See `docs/frontend/dev.md`.

## Deleting a catalog record: what goes with it

**Foreign-keyed relations already cascade, and deliberately do not fire on a
soft delete.** `health_goal_product`, `ingredient_product`, `package_product`
and `product_coas` are all `ON DELETE CASCADE`; `plans` is `SET NULL`. A soft
delete is an UPDATE setting `deleted_at`, so the database removes nothing — which
is correct, because a restored record must come back with its classifications
intact. The cascades fire on `forceDelete()`, where the record cannot return.

**Polymorphic relations have no foreign key and need code.** A FK cannot span a
`*_type` column, so `categorizables`, `taggables`, `faqables`, `reviews`,
`catalog_item_sections`, `fulfillment_center_skus` and **both ends of
`catalog_relations`** would survive their record with nothing to remove them.
`catalog_relations` is double-polymorphic and is the largest of them — it drives
the Related / Pairs-well-with rails — so a record must be cleared as `source`
AND as `related`, or a deleted product keeps appearing in other products' rails.

`PurgesMorphRelationsOnForceDelete` clears them, on **force delete only**, from
a `protected array $morphPivots` declared on the model. It is applied to
`Product` and `Package`.

**The hazard it closes is not untidiness.** An orphan row keyed on `product #8`
belongs to whatever record next occupies id 8 — note that a normal insert will
not reissue it, since InnoDB persists the auto-increment counter from MySQL 8;
the exposure is explicit-id writes, which this project uses in its fill scripts,
the compound import and any database restore — the categories, tags and FAQs of a deleted product reappearing on an
unrelated one, with nothing in the admin to explain it. There is a test for
exactly that.
