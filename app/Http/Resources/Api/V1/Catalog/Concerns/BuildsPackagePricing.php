<?php

namespace App\Http\Resources\Api\V1\Catalog\Concerns;

use App\Enums\BillingPeriod;
use Illuminate\Support\Collection;

/**
 * The one implementation of what a package costs.
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
 * NOTE there is still a second range implementation outside this file, in
 * QuizSchemaBuilder::priceRange(), which computes live rather than reading a
 * resource. The two must move together. Folding it in here is worth doing and
 * is not this change.
 */
trait BuildsPackagePricing
{
    /** What a single purchase of the package itself costs, sale winning. */
    private function packageEffectivePrice(?float $sale, ?float $retail): ?float
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
     * The single figure a listing card leads with, and — when that figure comes
     * from a plan — the plan it came from.
     *
     * THE PACKAGE'S OWN PRICE WINS WHENEVER IT HAS ONE, even against a cheaper
     * plan. That is a commercial rule, not an arithmetic one, and it follows
     * from what the two things ARE on this install: a package is a set group of
     * products bought ONCE (1 of X, 1 of Y, 1 of Z, no rebill), while a plan
     * lays a monthly or prepaid RECURRING commitment over the same bundle.
     * They are two different transactions, not two labels for one. So the
     * cheapest number across both is not "what this costs" — it is the entry
     * price of a subscription, quoted on a card whose button buys a bundle.
     *
     * Leading with the plan understates the card by $50-$119 on live data and
     * commits the buyer to a rebill they did not choose; leading with the own
     * price quotes exactly what the visitor is about to buy. The plans are not
     * hidden — the stack's own page is where a visitor picks one, and that is
     * the screen where a recurring commitment should be presented.
     *
     * WHY THIS IS NOT `price_range` WITH THE TOP END DROPPED. The range spans
     * every way of buying, and those ways are not priced in the same unit: on
     * this install every package's cheapest offer is a monthly rate and its
     * dearest is a multi-month PREPAY TOTAL, so the honest span reads
     * "$279.99 - $1,259.96" and a visitor sees a stack that might cost $1,259.96
     * a month. `price_range` is still correct for what it measures and is still
     * emitted; a card just cannot show two numbers in two units side by side.
     *
     * `plan_id` NAMES THE PLAN THE FIGURE CAME FROM, or null when the package's
     * OWN price won. It carries two meanings, and both are load-bearing:
     *
     *   - The cart adds by it. A card that quotes one price and then adds a
     *     different one has lied at the last possible moment, and the
     *     alternative — having the frontend search the plans for one whose
     *     price matches the string it rendered — is reverse-engineering an
     *     answer this method already knows.
     *   - It says whether the figure is EXACT or a floor. Null means the own
     *     price: one number, one purchase, no rebill, so a card renders it
     *     bare. Non-null means the cheapest of several plans, so a card renders
     *     "From $X". `catalogCardPrice` in the frontend reads it for exactly
     *     that, and there is deliberately no second flag saying the same thing
     *     twice.
     *
     * THE FALLBACK IS THE ONLY PLACE THIS IS STILL A "FROM". A package with no
     * own price can only be bought through a plan, and rendering nothing would
     * hide a purchasable stack. So it takes the lowest MONTHLY-cadence price,
     * carrying that price's OWN suffix rather than a "/mo" this method invents
     * — a plan's cadence is structural (`billing_period`, an enum) and
     * trustworthy. Failing even that (a package sold only as a 6-month prepay),
     * it takes the cheapest price of any cadence with that price's suffix, so
     * the card says "From $899.00/6mo", which is true, rather than
     * "$899.00/mo", which is not.
     *
     * Intro prices are excluded deliberately: `intro_price` buys one billing
     * cycle, so leading a card with it advertises a number the visitor pays
     * once. The detail page's plan picker is where that offer belongs.
     *
     * THE SUFFIX ON THE OWN PRICE IS PASSED THROUGH VERBATIM AND IS NOT
     * VALIDATED HERE. A package's own price has no cadence column at all —
     * only the free-text `price_suffix` an operator typed — so nothing in this
     * method can tell "/mo" from "/ea" or know whether either is true. Under
     * the model above that suffix should normally be EMPTY, because a one-time
     * bundle price is not charged per period; four packages carried a
     * mislabelled "/mo" until the rule changed and were cleared with it. If a
     * card ever reads "$399.00/mo" for a purchase the cart books once, the bug
     * is in the data, not here. Inventing a unit for a number that has none is
     * the one thing this method must never do.
     *
     * ON AN EQUAL PRICE BETWEEN TWO PLANS, THE NON-RECURRING ONE WINS. Only
     * reachable in the fallback now that the own price pre-empts every plan,
     * but the reasoning is unchanged and is the same reasoning as the rule
     * above: downstream, prescribe-rx reads a package with no plan id as a
     * single transaction and a recurring plan as a subscription, and on a local
     * checkout the same choice decides whether the merchant account starts
     * auto-billing. Ordering equals by rebill costs three lines and keeps that
     * decision from falling out of collection order.
     *
     * CAVEAT, AND IT IS A SCHEMA GAP RATHER THAN A RULE: "no plan means no
     * rebill" holds here only because `packages` carries NO recurring column at
     * all — every billing field (`is_recurring`, `billing_mode`,
     * `rebill_strategy`, `trial_days`, `billing_period`, `term_months`) lives on
     * `plans`. prescribe-rx models recurrence on the PACKAGE as well as the
     * plan, so a recurring package with no plan is a shape that exists over
     * there and cannot be represented here. If a package-level recurring flag is
     * ever added, this method must read it — the own price would no longer be
     * evidence of a single transaction, and `plan_id: null` would no longer be
     * safe to render bare.
     *
     * @param  Collection<int, mixed>  $plans
     * @return array{amount: float|null, suffix: string|null, plan_id: int|null, currency: string}
     */
    private function packagePriceFrom(Collection $plans, ?float $ownEffective, ?string $ownSuffix): array
    {
        // The bundle is buyable outright, so that is what the card quotes.
        // Deliberately BEFORE the plans are looked at: this is not a comparison.
        if ($ownEffective !== null) {
            return [
                'amount' => round($ownEffective, 2),
                'suffix' => $ownSuffix,
                'plan_id' => null,
                'currency' => 'USD',
            ];
        }

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

        $monthly = $priced
            ->filter(fn ($p) => $p->billing_period === BillingPeriod::Monthly)
            ->map($candidate)
            ->values();

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
