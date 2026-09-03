<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Catalog\Category;
use App\Models\Catalog\Ingredient;
use App\Models\Kb\HealthGoal;
use App\Models\Catalog\Package;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductClass;
use App\Models\Catalog\ProductForm;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/catalog/facets
 */
class FacetController extends ApiController
{
    /**
     * Get catalog filter facets.
     *
     * Returns the option lists a catalog filter UI needs: categories, classes,
     * types, forms, and ingredients (each with published-product counts),
     * availability counts, and TWO sets of price bounds — `price` across
     * published products, `package_price` across published packages.
     *
     * The two price blocks exist because one endpoint serves both listings and
     * the two are not priced alike: a product's card shows its own effective
     * price, a package's shows the cheapest way in across its own price and its
     * monthly plans. A slider fed the wrong one filters on a range its own
     * labels contradict.
     *
     * KNOWN AND NOT FIXED HERE: every other facet group is still
     * product-scoped. The counts are published-PRODUCT counts, and a category
     * or tag attached only to packages is dropped from the payload entirely
     * (`products_count > 0`), so it cannot be selected on the package listing
     * even though PackageController honours those same slugs. Availability is
     * likewise a product count. That is a larger change than the price bounds —
     * it needs a scoping parameter and a decision about what the counts mean —
     * and is recorded rather than half-done.
     *
     * Facet values with zero published products are omitted.
     *
     * @tags Catalog
     *
     * @unauthenticated
     */
    public function index(): JsonResponse
    {
        $published = fn (Builder $q) => $q->where('status', CatalogStatus::Published);

        // How many published packages this goal would actually return on the
        // stacks listing — mirroring PackageController's filter, which mirrors
        // the badge builder. Kept as one closure so the three cannot drift.
        $effectivePackageCount = fn (HealthGoal $goal) => Package::query()
            ->where('status', CatalogStatus::Published)
            ->where(fn ($q) => $q
                ->whereHas('healthGoals', fn ($q) => $q->whereKey($goal->getKey()))
                ->orWhere(fn ($q) => $q
                    ->whereDoesntHave('healthGoals')
                    ->whereHas('healthGoalSourceProducts.healthGoals', fn ($q) => $q->whereKey($goal->getKey()))))
            ->count();

        // EVERY ROW CARRIES BOTH COUNTS, because one endpoint serves two
        // listings and a facet with products behind it may have no packages at
        // all — offering it on the stacks listing is an option that leads
        // nowhere. This mirrors what `price` / `package_price` below already do
        // for the slider: emit both figures, let each listing read its own.
        //
        // `count` keeps its original meaning (published PRODUCTS) so existing
        // consumers are unaffected; `package_count` is additive.
        //
        // A row is kept when EITHER count is non-zero — dropping on products
        // alone is what hid package-only categories from the stacks filter.
        $facet = fn ($collection) => $collection
            ->filter(fn ($row) => $row->products_count > 0 || ($row->packages_count ?? 0) > 0)
            ->map(fn ($row) => [
                'name' => $row->name,
                'slug' => $row->slug,
                'count' => $row->products_count,
                'package_count' => $row->packages_count ?? 0,
            ])->values()->all();

        // Both blocks measure the figure their cards show, via the one shared
        // expression — so a slider's ends, the rows it keeps and the order they
        // appear in cannot disagree with each other or with the cards.
        $priceBounds = Product::query()
            ->where('status', CatalogStatus::Published)
            ->selectRaw(
                'MIN('.Product::priceFromAmountSql().' + 0) as min_price, '
                .'MAX('.Product::priceFromAmountSql().' + 0) as max_price'
            )
            ->first();

        // PACKAGES NEED THEIR OWN BOUNDS, not the products'. One endpoint
        // serves both listings, and the package listing was reading `price`
        // above: a slider labelled with the product range, filtering package
        // figures. A stack priced outside the product range was unreachable by
        // the control meant to find it.
        $packagePriceBounds = Package::query()
            ->where('status', CatalogStatus::Published)
            ->selectRaw(
                'MIN('.Package::priceFromAmountSql().' + 0) as min_price, '
                .'MAX('.Package::priceFromAmountSql().' + 0) as max_price'
            )
            ->first();

        $bounds = fn (?object $row) => [
            'min' => $row?->min_price !== null ? (float) $row->min_price : null,
            'max' => $row?->max_price !== null ? (float) $row->max_price : null,
            'currency' => 'USD',
        ];

        return $this->success([
            // FIRST, because it is the only classification the catalog is
            // actually populated with: every published product carries health
            // goals, while categories are a merchandising axis an operator
            // fills in per install. A UI that renders groups in payload order
            // therefore leads with the filter that has options in it.
            //
            // Same vocabulary as the quiz (`show_in_quiz` decides only whether
            // a goal is OFFERED there, not whether it classifies), so filtering
            // a listing and matching a quiz answer cannot disagree about what a
            // product is for.
            // `packages` here is the OVERRIDE edge, which is empty in the
            // normal case — counting it alone reported zero for every goal and
            // hid the whole group from the stacks filter while the cards were
            // visibly badged. `packagesEffective` counts what the listing
            // actually returns: an override when the package has one, otherwise
            // the goals of its published contents.
            'goals' => $facet(HealthGoal::query()
                ->where('is_active', true)
                ->orderBy('position')
                ->withCount(['products' => $published])
                ->get()
                ->each(fn ($goal) => $goal->packages_count = $effectivePackageCount($goal))),
            'categories' => $facet(Category::query()
                ->where('is_visible', true)
                ->orderBy('position')
                ->withCount(['products' => $published, 'packages' => $published])
                ->get()),
            'classes' => $facet(ProductClass::query()
                ->active()
                ->orderBy('position')
                ->withCount(['products' => $published])
                ->get()),
            'types' => $facet(ProductType::query()
                ->active()
                ->orderBy('position')
                ->withCount(['products' => $published])
                ->get()),
            'forms' => $facet(ProductForm::query()
                ->active()
                ->orderBy('position')
                ->withCount(['products' => $published])
                ->get()),
            'ingredients' => $facet(Ingredient::query()
                ->active()
                ->orderBy('position')
                ->withCount(['products' => $published])
                ->get()),
            'tags' => $facet(Tag::query()
                ->where('is_visible', true)
                ->orderBy('position')
                ->withCount(['products' => $published])
                ->get()),
            'price' => $bounds($priceBounds),

            // Additive, never a reshaping of `price`: this payload ships to
            // more than one frontend and `price` has always meant products.
            'package_price' => $bounds($packagePriceBounds),
            'availability' => [
                'in_stock' => Product::query()->where('status', CatalogStatus::Published)->where('is_in_stock', true)->count(),
                'out_of_stock' => Product::query()->where('status', CatalogStatus::Published)->where('is_in_stock', false)->count(),
            ],
        ]);
    }
}
