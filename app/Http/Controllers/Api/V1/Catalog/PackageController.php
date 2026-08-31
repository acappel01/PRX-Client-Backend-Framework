<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Catalog\PackageResource;
use App\Models\Catalog\Package;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/catalog/packages
 * GET /api/v1/catalog/packages/{slug}
 */
class PackageController extends ApiController
{
    use Concerns\SortsCatalogQueries;

    /**
     * List published catalog packages.
     *
     * Returns a paginated list of published packages with their plans, categories, and tags.
     *
     * @tags Catalog
     *
     * @unauthenticated
     */
    #[QueryParameter('search', 'Filter by package name or subtitle.', type: 'string', example: 'hormone')]
    #[QueryParameter('price_min', 'Filter by the figure a card shows (`price_from.amount`, the "as low as" price) at or above this amount (USD).', type: 'float', infer: false, example: 50)]
    #[QueryParameter('price_max', 'Filter by the figure a card shows (`price_from.amount`, the "as low as" price) at or below this amount (USD).', type: 'float', infer: false, example: 300)]
    #[QueryParameter('sort', 'Sort order: position (default), name, -name, price, -price, newest, oldest. Price sorts by the same figure the price filter uses.', type: 'string', example: '-price')]
    #[QueryParameter('per_page', 'Results per page (1–50, default 15).', type: 'integer', example: 15)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->integer('per_page', 15), 50);

        $packages = Package::query()
            ->where('status', CatalogStatus::Published)
            ->with([
                'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
                'categories',
                'tags',
                // Badges are derived from the contained products unless the
                // package overrides them, so a listing needs both edges or
                // every card renders bare. Two extra queries for the page,
                // not two per row.
                'healthGoals',
                'healthGoalSourceProducts.healthGoals',
            ])
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'categories',
                fn ($q) => $q->where('slug', $request->string('category'))
            ))
            ->when($request->filled('tag'), fn ($q) => $q->whereHas(
                'tags',
                fn ($q) => $q->where('slug', $request->string('tag'))
            ))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('is_in_stock', true))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term)->orWhere('subtitle', 'like', $term);
            }))
            ->when(
                $request->filled('price_min') || $request->filled('price_max'),
                // FILTERS ON THE FIGURE THE CARD SHOWS, which is the whole point
                // of this block. It used to be `whereHas('plans', ...)` on plan
                // prices alone, so a package's own price was invisible to the
                // filter and a package with NO plans could never match any price
                // range at all. Live symptom: cards read "As low as $399.00"
                // under a slider that dropped them at a $350 minimum, because
                // their plans were $279.99 and $671.98 with nothing between.
                function ($q) use ($request): void {
                    $figure = Package::priceFromAmountSql();

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
            // Ordered by the same figure, so "price ascending" matches what the
            // cards read rather than the packages' own columns.
            ->tap(fn ($q) => $this->applyCatalogSort($q, $request->input('sort'), Package::priceFromAmountSql()))
            ->paginate($perPage);

        return PackageResource::collection($packages);
    }

    /**
     * Get a published catalog package by slug.
     *
     * Includes published plans, included products, categories, and tags.
     *
     * @tags Catalog
     *
     * @unauthenticated
     */
    public function show(Package $package): JsonResponse
    {
        abort_if($package->status !== CatalogStatus::Published, 404);

        $package->load([
            'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
            'products' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderByPivot('sort_order'),
            // BOTH, and they are not redundant. `healthGoalSourceProducts`
            // feeds the PACKAGE's derived badges and is never serialized;
            // `products.healthGoals` feeds the badges on each nested product,
            // which is what the "What's Included" rows render. They are
            // separate relations hydrating separate model instances, so
            // loading one leaves the other's `relationLoaded` false and
            // ProductResource serves `health_goals: []` — indistinguishable
            // from an untagged product. Dropping this line is how that
            // happened once already.
            'products.healthGoals',
            'healthGoalSourceProducts.healthGoals',
            'healthGoals',
            'categories',
            'tags',
            'faqs' => fn ($q) => $q->where('is_published', true),
            'faqs.category',
            'approvedReviews',
            'sections.globalSection',
        ]);

        return $this->success((new PackageResource($package))->toArray(request()));
    }
}
