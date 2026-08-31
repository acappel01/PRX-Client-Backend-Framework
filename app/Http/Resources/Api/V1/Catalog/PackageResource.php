<?php

namespace App\Http\Resources\Api\V1\Catalog;

use App\Services\Cms\SectionDataTransformer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PackageResource extends JsonResource
{
    use Concerns\BuildsHealthGoalBadges;
    use Concerns\BuildsPackagePricing;
    use Concerns\BuildsRatingSummary;
    use Concerns\NormalizesDetailSections;
    use Concerns\NormalizesHighlights;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<array{text: string, icon: string|null}> $highlights */
        $highlights = $this->normalizeHighlights($this->highlights);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'hero_image_url' => $this->hero_image_path ? Storage::disk('public')->url($this->hero_image_path) : null,
            'banner_image_url' => $this->banner_image_path ? Storage::disk('public')->url($this->banner_image_path) : null,
            'gallery' => collect($this->gallery ?? [])->map(fn ($p) => Storage::disk('public')->url($p))->values()->all(),
            'status' => $this->status->value,
            'tier' => $this->tier,
            'badge_text' => $this->badge_text,
            'highlights' => $highlights,
            'health_goals' => $this->packageHealthGoalBadges($this->resource),
            'is_featured' => (bool) $this->is_featured,
            'is_in_stock' => (bool) $this->is_in_stock,
            'is_on_sale' => (bool) ($this->sale_price !== null
                || ($this->relationLoaded('plans') && $this->plans->contains(fn ($p) => $p->sale_price !== null))),
            'requires_lab' => (bool) $this->requires_lab,
            'sort_order' => $this->position,

            // THE PACKAGE'S OWN PRICE, which used to be emitted nowhere.
            //
            // A package is buyable on its own — it IS a product, or a group of
            // products — and its price is what the detail page shows. Plans are
            // the separate, subscription-shaped offer alongside it. Until now
            // this resource emitted only `price_range`, computed from PLAN
            // prices, so an operator could set the package to $399, save it
            // successfully, and watch the storefront keep showing the default
            // plan's $279.99. Nothing failed — the value had no way out of the
            // database, which is indistinguishable from a save that did not work.
            'price' => [
                'retail' => $this->retail_price !== null ? (float) $this->retail_price : null,
                'sale' => $this->sale_price !== null ? (float) $this->sale_price : null,
                'effective' => $this->effectivePrice(),
                'suffix' => $this->price_suffix,
                'currency' => 'USD',
            ],

            // Emitted whenever the plans relation was loaded, INCLUDING when it
            // is empty: a package with no plans still has its own price, and a
            // range of one number is the honest answer rather than no range.
            'price_range' => $this->when(
                $this->relationLoaded('plans'),
                fn () => $this->buildPriceRange()
            ),

            // The one figure a listing card leads with: the package's own
            // price when it has one, because a package is a set group of
            // products bought once and its plans are separate recurring
            // commitments shown on the detail page. `plan_id` is null in that
            // case, which also tells a card to render the figure bare rather
            // than as "From $X". Emitted alongside `price_range`, not instead
            // of it — the two answer different questions, and the range's ends
            // are in different units. See
            // BuildsPackagePricing::packagePriceFrom().
            'price_from' => $this->when(
                $this->relationLoaded('plans'),
                fn () => $this->buildPriceFrom()
            ),
            'detail_sections' => $this->when(
                $request->routeIs('api.v1.catalog.packages.show'),
                fn () => $this->normalizeDetailSections($this->detail_sections)
            ),
            'detail_layout' => $this->when(
                $request->routeIs('api.v1.catalog.packages.show'),
                $this->detail_layout
            ),
            'sections' => $this->whenLoaded(
                'sections',
                fn () => app(SectionDataTransformer::class)->transform($this->sections)
            ),
            'seo' => $this->when($request->routeIs('api.v1.catalog.packages.show'), [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'og_image_url' => $this->og_image_path ? Storage::disk('public')->url($this->og_image_path) : null,
            ]),
            'provider' => [
                'package_id' => $this->provider_package_id,
                'package_sku' => $this->provider_package_sku,
                'encounter_type_id' => $this->provider_encounter_type_id,
            ],
            'products' => ProductResource::collection($this->whenLoaded('products')),
            'plans' => PlanResource::collection($this->whenLoaded('plans')),
            'faqs' => $this->whenLoaded('faqs', fn () => $this->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'category' => $faq->category?->name,
            ])->values()->all()),
            'rating' => $this->whenLoaded('approvedReviews', fn () => $this->ratingFromLoadedReviews()),
            'reviews' => $this->whenLoaded('approvedReviews', fn () => $this->approvedReviews->map(fn ($review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'author_name' => $review->author_name,
                'title' => $review->title,
                'body' => $review->body,
                'reviewed_at' => $review->reviewed_at?->toDateString(),
            ])->values()->all()),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'related' => $this->when(
                $request->routeIs('api.v1.catalog.packages.show'),
                fn () => CatalogRelationItemResource::collection($this->relatedItems())->toArray($request)
            ),
            'pairs_with' => $this->when(
                $request->routeIs('api.v1.catalog.packages.show'),
                fn () => CatalogRelationItemResource::collection($this->pairsWithItems())->toArray($request)
            ),
        ];
    }

    /** What a single purchase of the package itself costs, sale winning. */
    private function effectivePrice(): ?float
    {
        return $this->packageEffectivePrice($this->sale_price, $this->retail_price);
    }

    /**
     * The span a visitor could pay — see BuildsPackagePricing for the rule and
     * for why the package's own price belongs in it.
     *
     * @return array{from: float|null, to: float|null, currency: string}
     */
    private function buildPriceRange(): array
    {
        return $this->packagePriceRange($this->plans, $this->effectivePrice());
    }

    /**
     * The "From $X/mo" a card leads with — see BuildsPackagePricing for why
     * this is not simply the low end of the range.
     *
     * @return array{amount: float|null, suffix: string|null, plan_id: int|null, currency: string}
     */
    private function buildPriceFrom(): array
    {
        return $this->packagePriceFrom($this->plans, $this->effectivePrice(), $this->price_suffix);
    }
}
