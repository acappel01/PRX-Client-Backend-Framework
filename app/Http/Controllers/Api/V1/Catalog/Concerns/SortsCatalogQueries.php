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
 * BOTH callers pass `priceFromAmountSql()` for their own kind, so the order
 * always matches the figures on screen. Products were briefly left on their own
 * columns on the grounds that a product's own price IS its card figure — true
 * only while no product carries a monthly plan, which is a coincidence rather
 * than a rule, and precisely the shape of the defect this parameter exists to
 * have fixed for packages.
 */
trait SortsCatalogQueries
{
    private function applyCatalogSort(Builder $query, ?string $sort, string $priceExpression): Builder
    {
        // REQUIRED, not defaulted. Both callers pass an expression now, so a
        // fallback would be dead code carrying an opinion about "the" price —
        // and the opinion it carried (a row's own effective price) is exactly
        // the one that made a listing disagree with its cards.
        //
        // `+ 0` coerces a TEXT-bound decimal to a number so SQLite compares it
        // numerically rather than lexically ("100" < "99").
        $price = $priceExpression.' + 0';

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
