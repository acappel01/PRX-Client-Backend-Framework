<?php

namespace App\Http\Controllers\Api\V1\Catalog\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared whitelist-based `sort` query-param handling for catalog index
 * endpoints.
 *
 * PRICE SORTING TAKES AN EXPRESSION, because "the price" is not the same
 * question for every catalog kind. A package's card shows the cheapest way in
 * across its own price and its monthly plans, so sorting it by its own columns
 * puts the list in an order the visitor cannot see — and a plan-only package,
 * whose own columns are NULL, sorts to the very top of "price ascending" in
 * MySQL regardless of what its plans cost.
 *
 * The default keeps products on their own columns; PackageController passes
 * `Package::priceFromAmountSql()` so the order matches the figures on screen.
 *
 * THE DEFAULT IS RIGHT FOR PRODUCTS ONLY WHILE NO PRODUCT CARRIES A MONTHLY
 * PLAN, and that is a coincidence rather than a rule. A product card renders
 * `price_from` now, exactly as a package card does; it merely equals the
 * product's own effective price today, because no product has a monthly plan
 * to undercut it. The day one does, a product's filter, sort and facet bounds
 * diverge from its cards in precisely the way this parameter exists to have
 * fixed for packages — the fix is the same shape (a product-side expression
 * correlating on `plans.product_id`), and it is not built. Do not read the
 * default as a statement that products are different in kind.
 */
trait SortsCatalogQueries
{
    private const OWN_EFFECTIVE_PRICE = 'COALESCE(sale_price, retail_price)';

    private function applyCatalogSort(Builder $query, ?string $sort, ?string $priceExpression = null): Builder
    {
        // `+ 0` coerces a TEXT-bound decimal to a number so SQLite compares it
        // numerically rather than lexically ("100" < "99").
        $price = ($priceExpression ?? self::OWN_EFFECTIVE_PRICE).' + 0';

        return match ($sort) {
            'name' => $query->orderBy('name'),
            '-name' => $query->orderByDesc('name'),
            'price' => $query->orderByRaw($price.' asc'),
            '-price' => $query->orderByRaw($price.' desc'),
            'newest' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            default => $query->orderBy('position')->orderBy('name'),
        };
    }
}
