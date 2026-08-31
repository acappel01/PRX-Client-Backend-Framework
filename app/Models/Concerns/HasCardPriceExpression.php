<?php

namespace App\Models\Concerns;

use App\Enums\BillingPeriod;
use App\Enums\CatalogStatus;

/**
 * The card figure ("as low as $X") as a SQL expression, for the surfaces that
 * must FILTER, SORT or AGGREGATE on it.
 *
 * THIS IS A SECOND EXPRESSION OF A RULE THAT LIVES IN PHP, AND THAT IS A DEBT
 * TAKEN DELIBERATELY. `BuildsCatalogPricing::catalogPriceFrom()` is the one
 * implementation of what a card quotes, and this file must not become a rival
 * to it. But `price_from` is COMPUTED, not stored, so SQL cannot filter, sort
 * or take MIN/MAX over it — and doing those in PHP would mean loading every
 * published row to answer a listing request.
 *
 * What makes the duplication survivable is that only the AMOUNT is mirrored,
 * and the amount is the stable half of the rule: the suffix, the `plan_id` and
 * the non-recurring tie-break all decide which candidate is REPORTED, never
 * what the lowest number is. Nothing here needs to know about them.
 *
 * `CatalogPriceParityTest` asserts this expression equals `price_from.amount`
 * for every branch, on BOTH kinds. **If you change the amount rule in the
 * trait, that test fails here.** Do not fix it by editing only one side.
 *
 * ONE EXPRESSION FOR BOTH KINDS, because there is one rule. Products and
 * packages differ only in which table they sit in and which column their plans
 * correlate on; writing it twice is how the two would drift apart while every
 * test still passed.
 *
 * THREE THINGS THAT WOULD MAKE IT LIE, all of them silent:
 *
 *   - **Soft deletes.** `plans` uses SoftDeletes, so the Eloquent relation
 *     hides trashed rows and raw SQL does not. There is a trashed plan in this
 *     install's data; without `deleted_at IS NULL` a deleted plan's price would
 *     filter and sort items it no longer belongs to.
 *   - **Unpublished plans**, which the relation constrains everywhere it is
 *     loaded.
 *   - **Intro prices**, which are never candidates — `intro_price` buys one
 *     billing cycle, so it must not decide a listing filter either.
 *
 * Intentionally NOT guarded: unpriced plans. The PHP rule filters them out
 * explicitly and mirroring that here reads like it matters — it does not.
 * `MIN()` ignores NULLs in both MySQL and SQLite, and a plan with neither price
 * COALESCEs to NULL, so it drops out on its own; if every plan is unpriced, MIN
 * is NULL and the CASE falls through correctly. An explicit guard was written
 * here first and a mutation run proved no test could kill it, because deleting
 * it changes nothing. Left out rather than kept as decoration a later reader
 * would take for load-bearing.
 *
 * Written with CASE and correlated scalar subqueries rather than LEAST(), which
 * does not exist in SQLite (tests) and returns NULL on any NULL argument in
 * MySQL (production).
 */
trait HasCardPriceExpression
{
    /**
     * @param  string  $table  The model's table, e.g. `packages`.
     * @param  string  $foreignKey  The column on `plans` pointing at it.
     */
    protected static function cardPriceExpression(string $table, string $foreignKey): string
    {
        $live = "p.{$foreignKey} = {$table}.id"
            ." AND p.status = '".CatalogStatus::Published->value."'"
            .' AND p.deleted_at IS NULL';

        $cheapest = fn (string $extra) => "(SELECT MIN(COALESCE(p.sale_price, p.retail_price)) FROM plans p WHERE {$live}{$extra})";

        $own = "COALESCE({$table}.sale_price, {$table}.retail_price)";
        $monthly = $cheapest(" AND p.billing_period = '".BillingPeriod::Monthly->value."'");
        $any = $cheapest('');

        return "(CASE
            WHEN {$own} IS NULL AND {$monthly} IS NULL THEN {$any}
            WHEN {$own} IS NULL THEN {$monthly}
            WHEN {$monthly} IS NULL THEN {$own}
            WHEN {$monthly} < {$own} THEN {$monthly}
            ELSE {$own}
        END)";
    }
}
