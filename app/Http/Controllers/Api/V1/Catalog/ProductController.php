<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Catalog\ProductResource;
use App\Models\Catalog\Product;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/catalog/products
 * GET /api/v1/catalog/products/{slug}
 */
class ProductController extends ApiController
{
    use Concerns\SortsCatalogQueries;

    /**
     * List published catalog products.
     *
     * Returns a paginated list of published products with categories and tags.
     *
     * @tags Catalog
     *
     * @unauthenticated
     */
    #[QueryParameter('search', 'Filter by product name or subtitle.', type: 'string', example: 'testosterone')]
    #[QueryParameter('class', 'Filter by product class slug.', type: 'string', example: 'peptides')]
    #[QueryParameter('type', 'Filter by product type slug.', type: 'string', example: 'blends')]
    #[QueryParameter('form', 'Filter by product form slug.', type: 'string', example: 'vial-lyophilized')]
    #[QueryParameter('ingredient', 'Filter by ingredient (compound) slug.', type: 'string', example: 'bpc-157')]
    #[QueryParameter('price_min', 'Filter by the figure a card shows (`price_from.amount`, the "as low as" price) at or above this amount (USD).', type: 'float', infer: false, example: 50)]
    #[QueryParameter('price_max', 'Filter by the figure a card shows (`price_from.amount`, the "as low as" price) at or below this amount (USD).', type: 'float', infer: false, example: 300)]
    #[QueryParameter('sort', 'Sort order: position (default), name, -name, price, -price, newest, oldest.', type: 'string', example: '-price')]
    #[QueryParameter('per_page', 'Results per page (1–50, default 15).', type: 'integer', example: 15)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->integer('per_page', 15), 50);

        $products = Product::query()
            ->where('status', CatalogStatus::Published)
            ->with([
                'categories', 'tags', 'healthGoals', 'productClass', 'productType',
                'productForm', 'administrationMethod', 'volumeUnit',
                // REQUIRED FOR `price_from` — ProductResource omits it silently
                // when this is missing, and a listing card would then disagree
                // with the detail page about the same product. Same constraint
                // as the show route and as PackageController.
                'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
            ])
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'categories',
                fn ($q) => $q->where('slug', $request->string('category'))
            ))
            ->when($request->filled('tag'), fn ($q) => $q->whereHas(
                'tags',
                fn ($q) => $q->where('slug', $request->string('tag'))
            ))
            ->when($request->filled('class'), fn ($q) => $q->whereHas(
                'productClass',
                fn ($q) => $q->where('slug', $request->string('class'))
            ))
            ->when($request->filled('type'), fn ($q) => $q->whereHas(
                'productType',
                fn ($q) => $q->where('slug', $request->string('type'))
            ))
            ->when($request->filled('form'), fn ($q) => $q->whereHas(
                'productForm',
                fn ($q) => $q->where('slug', $request->string('form'))
            ))
            ->when($request->filled('ingredient'), fn ($q) => $q->whereHas(
                'ingredients',
                fn ($q) => $q->where('slug', $request->string('ingredient'))
            ))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('is_in_stock', true))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term)->orWhere('subtitle', 'like', $term);
            }))
            ->when(
                $request->filled('price_min') || $request->filled('price_max'),
                // FILTERS ON THE FIGURE THE CARD SHOWS, the same rule packages
                // use. A product's own effective price is that figure today,
                // because no product carries a monthly plan to undercut it — so
                // this is not a behaviour change, it is the removal of a
                // coincidence. The day a product gets one, its filter, sort and
                // slider follow the cards instead of quietly disagreeing, which
                // is the defect /stacks had.
                function ($q) use ($request): void {
                    $figure = Product::priceFromAmountSql();

                    // `? + 0` coerces the TEXT-bound float to REAL for correct
                    // SQLite comparison.
                    if ($request->filled('price_min')) {
                        $q->whereRaw("{$figure} >= ? + 0", [(float) $request->input('price_min')]);
                    }
                    if ($request->filled('price_max')) {
                        $q->whereRaw("{$figure} <= ? + 0", [(float) $request->input('price_max')]);
                    }
                }
            )
            ->tap(fn ($q) => $this->applyCatalogSort($q, $request->input('sort'), Product::priceFromAmountSql()))
            ->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Get a published catalog product by slug.
     *
     * Includes categories, tags, and any packages that contain this product along with their plans.
     *
     * @tags Catalog
     *
     * @unauthenticated
     */
    public function show(Product $product): JsonResponse
    {
        abort_if($product->status !== CatalogStatus::Published, 404);

        $product->load([
            'categories',
            'tags',
            'healthGoals',
            // `packages.plans` used to be loaded here. ProductResource
            // serializes no `packages` key and the controller does nothing
            // else with the product, so it was two queries per detail request
            // feeding nothing. Removed 2026-08-30; if a "sold in these stacks"
            // block ever lands, it comes back WITH the resource field.
            'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
            'productClass',
            'productType',
            'productForm',
            'administrationMethod',
            'volumeUnit',
            'ingredients',
            'coas',
            'faqs' => fn ($q) => $q->where('is_published', true),
            'faqs.category',
            'approvedReviews',
            'sections.globalSection',
        ]);

        return $this->success((new ProductResource($product))->toArray(request()));
    }
}
