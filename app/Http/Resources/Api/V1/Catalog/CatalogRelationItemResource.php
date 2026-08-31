<?php

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\Catalog\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Lightweight card reference for related / pairs-with catalog items.
 * The underlying resource may be a Product or a Package; `type` tells the
 * frontend which detail route to link to.
 *
 * A package prices ITSELF. This used to substitute the package with its
 * default (or first) published plan, on the once-true reasoning that packages
 * carried no price columns; they do now, so a $399 buy-once stack advertised
 * its plan's $279.99 on every upsell and pairs-with card. The rule lives in
 * BuildsCatalogPricing so this cannot drift from the detail page again.
 *
 * `price_range` rides along for packages, so that dropping the plan
 * substitution could not leave a plan-sold package with nothing to show.
 *
 * IT IS NO LONGER WHAT A CARD SHOULD READ, THOUGH. The range's two ends are in
 * different units — a monthly rate against a multi-month prepay total — so a
 * card leading with its floor prints an unlabelled number a visitor reads as
 * monthly. `price_from` is the figure for that, and it carries its own suffix.
 * The range stays because it is still the honest answer to "what could I pay".
 *
 * `effective` is null (not 0.00) when no price exists at all.
 */
class CatalogRelationItemResource extends JsonResource
{
    use Concerns\BuildsCatalogPricing;
    use Concerns\BuildsHealthGoalBadges;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource instanceof Package ? 'package' : 'product',
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            // The rail cards carry a blurb, so the light card needs it. Both
            // Product and Package have this column; it is trimmed to a word
            // budget on the frontend, not here, because the cap is a layout
            // concern that differs per surface.
            'short_description' => $this->short_description,
            'badge_text' => $this->badge_text,
            'hero_image_url' => $this->hero_image_path ? Storage::disk('public')->url($this->hero_image_path) : null,
            'is_in_stock' => (bool) $this->is_in_stock,
            'price' => $this->priceBlock(),
            'price_range' => $this->priceRangeBlock(),
            'price_from' => $this->priceFromBlock(),
            'health_goals' => $this->relationBadges(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceBlock(): array
    {
        $source = $this->resource;

        $retail = $source->retail_price ?? null;
        $sale = $source->sale_price ?? null;
        $effective = $sale ?? $retail;

        // No Plan fallback here any more. This used to read a substituted
        // plan's billing period; nothing is substituted, and a rail item is
        // only ever a Product or a Package.
        $suffix = $source->price_suffix ?? null;

        return [
            'retail' => $retail !== null ? (float) $retail : null,
            'sale' => $sale !== null ? (float) $sale : null,
            'effective' => $effective !== null ? (float) $effective : null,
            'suffix' => $suffix,
            'currency' => 'USD',
        ];
    }

    /**
     * Badges for either side of the polymorphic relation.
     *
     * A product carries its own; a package derives from its contents unless it
     * overrides. Both return [] when the edge was not eager-loaded, so a rail
     * that forgot the load renders bare rather than firing a query per card.
     *
     * @return list<array{name: string, slug: string, badge_color: string|null}>
     */
    private function relationBadges(): array
    {
        $source = $this->resource;

        if ($source instanceof Package) {
            return $this->packageHealthGoalBadges($source);
        }

        return $source->relationLoaded('healthGoals')
            ? $this->healthGoalBadges($source->healthGoals)
            : [];
    }

    /**
     * Only packages get a range — a product's own price is the whole story on
     * a rail card. Null rather than an empty range when plans were not loaded,
     * so a card can tell "no range" from "range of nothing".
     *
     * @return array{from: float|null, to: float|null, currency: string}|null
     */
    private function priceRangeBlock(): ?array
    {
        $source = $this->resource;

        if (! $source instanceof Package || ! $source->relationLoaded('plans')) {
            return null;
        }

        return $this->packagePriceRange(
            $source->plans,
            $this->catalogEffectivePrice($source->sale_price, $source->retail_price),
        );
    }

    /**
     * The "As low as $X" figure a rail card leads with — BOTH KINDS.
     *
     * A rail mixes products and packages in one row, so a figure computed for
     * one kind and not the other is visible as a row where some cards say
     * "As low as $349.00/mo" and their neighbours say "$249.00". This resource
     * gated on Package until the rule became catalogue-wide, and the gate
     * outlived the reason: it made the plans eager load in
     * HasCatalogRelations dead for products, running the query and discarding
     * the answer.
     *
     * The mixed-unit worry that once justified excluding products is handled
     * inside the rule, not here — only monthly-cadence plans join the pool, so
     * a product's 3/6/9/12-month prepay totals can never become its card
     * figure. See BuildsCatalogPricing::catalogPriceFrom.
     *
     * Null when plans were not loaded, so a card can tell "no figure" from "a
     * figure of nothing" — the plans are needed even for an item whose own
     * price wins, because deciding that requires knowing what the plans cost.
     *
     * @return array{amount: float|null, suffix: string|null, plan_id: int|null, currency: string}|null
     */
    private function priceFromBlock(): ?array
    {
        $source = $this->resource;

        if (! $source->relationLoaded('plans')) {
            return null;
        }

        return $this->catalogPriceFrom(
            $source->plans,
            $this->catalogEffectivePrice($source->sale_price ?? null, $source->retail_price ?? null),
            $source->price_suffix ?? null,
        );
    }
}
