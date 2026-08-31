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
 * BuildsPackagePricing so this cannot drift from the detail page again.
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
    use Concerns\BuildsHealthGoalBadges;
    use Concerns\BuildsPackagePricing;

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
            $this->packageEffectivePrice($source->sale_price, $source->retail_price),
        );
    }

    /**
     * The figure a card leads with, packages only and on the same terms as the
     * range above: null when this is a product, or when plans were not loaded,
     * so a card can tell "no figure" from "a figure of nothing".
     *
     * The plans still have to be loaded even though a package with an own price
     * never reads them — the rule falls back to them for a package with no own
     * price, and a resource that guessed which branch applied before loading
     * would be the same silent-omission trap from the other direction.
     *
     * A PRODUCT DELIBERATELY GETS NONE. Its own price is the whole story on a
     * card, and a product's term plans are 3/6/9/12-month prepay totals — a
     * "from" built out of those would be the mixed-unit bug this field exists
     * to avoid, pointed at products instead.
     *
     * @return array{amount: float|null, suffix: string|null, plan_id: int|null, currency: string}|null
     */
    private function priceFromBlock(): ?array
    {
        $source = $this->resource;

        if (! $source instanceof Package || ! $source->relationLoaded('plans')) {
            return null;
        }

        return $this->packagePriceFrom(
            $source->plans,
            $this->packageEffectivePrice($source->sale_price, $source->retail_price),
            $source->price_suffix,
        );
    }
}
