<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Catalog\Category;
use App\Models\Catalog\Ingredient;
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

        $facet = fn ($collection) => $collection
            ->filter(fn ($row) => $row->products_count > 0)
            ->map(fn ($row) => [
                'name' => $row->name,
                'slug' => $row->slug,
                'count' => $row->products_count,
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
            'categories' => $facet(Category::query()
                ->where('is_visible', true)
                ->orderBy('position')
                ->withCount(['products' => $published])
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
