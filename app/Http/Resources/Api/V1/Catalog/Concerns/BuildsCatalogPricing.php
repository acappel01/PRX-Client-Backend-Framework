<?php

namespace App\Http\Resources\Api\V1\Catalog\Concerns;

use App\Enums\BillingPeriod;
use Illuminate\Support\Collection;

/**
 * The one implementation of what a catalog item costs on a card.
 *
 * Serves products AND packages. It was named for packages when only packages
 * had a card figure; a product now carries `price_from` too, and one rule for
 * both is the entire point of the file.
 *
 * Extracted when the relation rails were found mispricing packages: they
 * substituted a package with its default plan, so a $399 buy-once stack
 * advertised the plan's $279.99 on every upsell and pairs-with card. The
 * substitution predates packages having price columns at all — see the
 * migration that added them — and the docblock asserting "packages carry no
 * price columns of their own" stayed true-looking long after it stopped being
 * true. Two implementations of a pricing rule is how that happens; there is
 * now one.
 *
 * THE RULE NOW EXISTS TWICE ON PURPOSE, AND ONLY TWICE. Here, deciding the
 * amount AND the suffix, the `plan_id` and the tie-break; and in
 * `HasCardPriceExpression`, which mirrors the AMOUNT alone as SQL because a
 * listing must FILTER, SORT and take MIN/MAX over a figure that is computed
 * rather than stored. `CatalogPriceParityTest` asserts the two agree on every
 * branch, for products and packages both — change the amount rule here and it
 * fails there.
 *
 * `QuizSchemaBuilder` used to be a third, computing its own live min/max from
 * plans alone. It now runs the shared expression. There is no other.
 */
trait BuildsCatalogPricing
{
    /** What a single purchase of the item itself costs, sale winning. */
    private function catalogEffectivePrice(?float $sale, ?float $retail): ?float
    {
        $effective = $sale ?? $retail;

        return $effective !== null ? (float) $effective : null;
    }

    /**
     * The span a visitor could pay, across every way of buying this.
     *
     * SPANS PLANS **AND** THE PACKAGE'S OWN PRICE. Usually the plans decide
     * it, because a subscription is discounted against a one-off purchase — so
     * the obvious implementation reads plans alone. That silently produces a
     * wrong "from" the moment a single purchase is discounted below the
     * cheapest plan, which is exactly what a sale on the package is for.
     *
     * Only PRICED candidates count: a plan with neither retail nor sale, or a
     * package with no price of its own, contributes nothing rather than a zero
     * that would drag `from` to 0.00.
     *
     * @param  Collection<int, mixed>  $plans
     * @return array{from: float|null, to: float|null, currency: string}
     */
    private function packagePriceRange(Collection $plans, ?float $ownEffective): array
    {
        $prices = $plans
            ->filter(fn ($p) => $p->sale_price !== null || $p->retail_price !== null)
            ->map(fn ($p) => (float) ($p->sale_price ?? $p->retail_price))
            ->values();

        if ($ownEffective !== null) {
            $prices->push($ownEffective);
        }

        return [
            'from' => $prices->isNotEmpty() ? round((float) $prices->min(), 2) : null,
            'to' => $prices->isNotEmpty() ? round((float) $prices->max(), 2) : null,
            'currency' => 'USD',
        ];
    }

    /**
     * The "As low as $X" figure a listing card leads with, and — when that
     * figure came from a plan — which plan.
     *
     * THE CHEAPEST WAY IN, ACROSS EVERY WAY OF BUYING THE ITEM. An item is
     * purchasable at its own price (once) and, if it carries plans, at a plan's
     * price (a recurring or prepaid commitment). A card advertises the lowest of
     * those and says "as low as", so the number is a floor the visitor can
     * actually reach rather than a claim about what they will pay. Which of the
     * two they end up on is chosen on the detail page, where the terms are
     * visible — a card never commits anyone to a plan.
     *
     * SERVES PRODUCTS AND PACKAGES BOTH, which is why it is not named for
     * packages. A product has an own price and may carry term plans exactly as a
     * package does; there is one rule for what a card quotes, and having two was
     * how the same item came to show two numbers on two screens.
     *
     * ONLY MONTHLY-CADENCE PLANS JOIN THE POOL, and that is the guard that makes
     * this safe to show alone. A plan's cadence is structural (`billing_period`,
     * an enum) and trustworthy, and comparing raw amounts across billing units
     * is meaningless: on this install a product's term plans are 3/6/9/12-month
     * PREPAY TOTALS, so pooling them unfiltered would let a $537.30 quarterly
     * total masquerade as a lower number than a monthly rate, or a package's
     * $1,259.96 six-month prepay sit under "as low as". `price_range` still
     * spans everything and is still emitted; it is the honest answer to "what
     * could I pay" and remains unshowable on a card for the same reason.
     *
     * THE ITEM'S OWN PRICE IS ALWAYS A CANDIDATE. It has no cadence column at
     * all — only the free-text `price_suffix` an operator typed — so it cannot
     * be cadence-filtered, and excluding it would hide the very case a sale
     * exists to create: a single purchase discounted below every plan.
     *
     * Intro prices are excluded deliberately: `intro_price` buys one billing
     * cycle, so leading a card with it advertises a number the visitor stops
     * paying. The detail page's plan picker is where that offer belongs.
     *
     * The fallback matters more than it looks. An item sold only as a 6-month
     * prepay has no monthly price to lead with, and rendering nothing would hide
     * something purchasable; it falls back to the cheapest price of any cadence,
     * with that price's suffix, so the card still says something true. It cannot
     * fire for an item that has its own price, which is every product here.
     *
     * `plan_id` NAMES THE PLAN THE FIGURE CAME FROM, or null when the item's OWN
     * price won. Null is meaningful rather than missing: it says "buy the item
     * itself", which the cart supports. **A surface that quotes this figure must
     * not silently add it to the cart** — the figure is a floor, and on live data
     * it usually names a recurring plan, so adding on the visitor's behalf turns
     * "as low as $279.99" into a subscription they did not choose. Send them to
     * the detail page to pick, or give them a plan picker. See PlanReport.
     *
     * ON AN EQUAL PRICE, THE NON-RECURRING OPTION WINS. This is not a tidiness
     * rule, it decides what the buyer is offered first. Downstream, prescribe-rx
     * reads an item with no plan id as a single transaction and a recurring plan
     * as a subscription, and on a local checkout the same choice decides whether
     * the merchant account starts auto-billing. So when a plan and the item's own
     * price are the same number, the figure resolves to the item — quoting the
     * plan would name a rebill where an identical one-off purchase exists. Live
     * example: Metabolic Reset, own price 399, plan #4 monthly retail 499 / sale
     * 399. A genuinely CHEAPER plan still wins; this orders equals, it does not
     * prefer one-time purchases over better prices.
     *
     * CAVEAT, AND IT IS A SCHEMA GAP RATHER THAN A RULE: "no plan means no
     * rebill" holds here only because `packages` carries NO recurring column at
     * all — every billing field (`is_recurring`, `billing_mode`,
     * `rebill_strategy`, `trial_days`, `billing_period`, `term_months`) lives on
     * `plans`. prescribe-rx models recurrence on the PACKAGE as well as the
     * plan, so a recurring package with no plan is a shape that exists over
     * there and cannot be represented here. If an item-level recurring flag is
     * ever added, this tie-break must read it — `plan_id: null` would no longer
     * be sufficient evidence of a single transaction.
     *
     * @param  Collection<int, mixed>  $plans
     * @return array{amount: float|null, suffix: string|null, plan_id: int|null, currency: string}
     */
    private function catalogPriceFrom(Collection $plans, ?float $ownEffective, ?string $ownSuffix): array
    {
        $priced = $plans->filter(fn ($p) => $p->sale_price !== null || $p->retail_price !== null);

        $candidate = fn ($p) => [
            'plan_id' => $p->id,
            'recurring' => (bool) $p->is_recurring,
            'amount' => (float) ($p->sale_price ?? $p->retail_price),
            // An operator's authored suffix wins over the cadence's default, so
            // "/month" instead of "/mo" stays the operator's call.
            'suffix' => $p->price_suffix ?: ($p->billing_period instanceof BillingPeriod
                ? $p->billing_period->suffix()
                : null),
        ];

        $monthly = collect();

        if ($ownEffective !== null) {
            // Buying the item alone never creates a rebill, and its suffix is
            // whatever the operator typed — passed through, never invented.
            //
            // PUSHED FIRST, AND THAT ORDER IS THE TIE-BREAK'S LAST STEP. The
            // sort below is stable, so two candidates equal on BOTH keys keep
            // insertion order — and an item's own price ties a NON-recurring
            // monthly plan on both. Mapping the plans first therefore resolved
            // such a tie to the plan while the rule says equals resolve to the
            // item. Harmless today (neither creates a rebill, so only the
            // add-versus-link branch moves), wrong as documented, and free to
            // fix here rather than with a third sort key.
            $monthly->push([
                'plan_id' => null,
                'recurring' => false,
                'amount' => $ownEffective,
                'suffix' => $ownSuffix,
            ]);
        }

        $monthly = $monthly->concat(
            $priced
                ->filter(fn ($p) => $p->billing_period === BillingPeriod::Monthly)
                ->map($candidate)
                ->values()
        )->values();

        $pool = $monthly->isNotEmpty() ? $monthly : $priced->map($candidate)->values();

        if ($pool->isEmpty()) {
            return ['amount' => null, 'suffix' => null, 'plan_id' => null, 'currency' => 'USD'];
        }

        // Amount decides; `recurring` only breaks a tie (false sorts before
        // true). See the note above on why that tie is a commercial choice.
        $cheapest = $pool->sortBy([
            ['amount', 'asc'],
            ['recurring', 'asc'],
        ])->first();

        return [
            'amount' => round((float) $cheapest['amount'], 2),
            'suffix' => $cheapest['suffix'],
            'plan_id' => $cheapest['plan_id'],
            'currency' => 'USD',
        ];
    }
}
