<?php

namespace App\Http\Resources\Api\V1\Catalog;

use App\Services\Cms\SectionDataTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    use Concerns\BuildsCatalogPricing;
    use Concerns\BuildsHealthGoalBadges;
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
            'description' => $this->when($request->routeIs('api.v1.catalog.products.show'), $this->description),
            'hero_image_url' => $this->hero_image_path ? Storage::disk('public')->url($this->hero_image_path) : null,
            'gallery' => collect($this->gallery ?? [])->map(fn ($p) => Storage::disk('public')->url($p))->values()->all(),
            'status' => $this->status->value,
            'badge_text' => $this->badge_text,
            'highlights' => $highlights,
            'health_goals' => $this->relationLoaded('healthGoals')
                ? $this->healthGoalBadges($this->healthGoals)
                : [],
            'is_featured' => (bool) $this->is_featured,
            'is_in_stock' => (bool) $this->is_in_stock,
            'inventory_status' => $this->inventory_status?->value,
            // The DISPLAY string beside the machine-readable value, the same
            // pairing PlanResource and ProfileResource use for their enums.
            // Without it the storefront printed the raw case — "in_stock" —
            // on the two products where an operator had actually set the
            // field, while the five with it null fell through to a hardcoded
            // "In Stock" in the frontend and looked correct. Copy is the
            // backend's to own; four enum cases must not be mapped over there.
            'inventory_status_label' => $this->inventory_status?->label(),
            'is_on_sale' => $this->sale_price !== null,
            'requires_lab' => (bool) $this->requires_lab,
            'rx_required' => (bool) $this->rx_required,
            'is_controlled_substance' => (bool) $this->is_controlled_substance,
            'sort_order' => $this->position,
            'classification' => [
                'class' => $this->lookup($this->whenLoaded('productClass')),
                'type' => $this->lookup($this->whenLoaded('productType')),
                'form' => $this->lookup($this->whenLoaded('productForm')),
                'administration_method' => $this->lookup($this->whenLoaded('administrationMethod')),
            ],
            'volume' => [
                'value' => $this->volume !== null ? (float) $this->volume : null,
                'unit' => $this->whenLoaded('volumeUnit', fn () => $this->volumeUnit?->abbreviation, null),
            ],
            'ingredients' => $this->whenLoaded('ingredients', fn () => $this->ingredients->map(fn ($ingredient) => [
                'name' => $ingredient->name,
                'slug' => $ingredient->slug,
                'concentration' => $ingredient->pivot->concentration !== null ? (float) $ingredient->pivot->concentration : null,
                'per_volume' => $ingredient->pivot->per_volume !== null ? (float) $ingredient->pivot->per_volume : null,
                'label' => $ingredient->pivot->potencyLabel(),
            ])->values()->all()),
            'detail_sections' => $this->when(
                $request->routeIs('api.v1.catalog.products.show'),
                fn () => $this->normalizeDetailSections($this->detail_sections)
            ),
            'detail_layout' => $this->when(
                $request->routeIs('api.v1.catalog.products.show'),
                $this->detail_layout
            ),
            'sections' => $this->whenLoaded(
                'sections',
                fn () => app(SectionDataTransformer::class)->transform($this->sections)
            ),
            'coas' => $this->whenLoaded('coas', fn () => $this->coas
                ->where('is_visible', true)
                ->map(fn ($coa) => [
                    'batch_number' => $coa->batch_number,
                    'file_url' => Storage::disk('public')->url($coa->file_path),
                    'file_type' => $coa->file_type,
                    'issued_at' => $coa->issued_at?->toDateString(),
                ])->values()->all()),
            'related' => $this->when(
                $request->routeIs('api.v1.catalog.products.show'),
                fn () => CatalogRelationItemResource::collection($this->relatedItems())->toArray($request)
            ),
            'pairs_with' => $this->when(
                $request->routeIs('api.v1.catalog.products.show'),
                fn () => CatalogRelationItemResource::collection($this->pairsWithItems())->toArray($request)
            ),
            'price' => [
                'retail' => $this->retail_price !== null ? (float) $this->retail_price : null,
                'sale' => $this->sale_price !== null ? (float) $this->sale_price : null,
                'effective' => (float) ($this->sale_price ?? $this->retail_price),
                'suffix' => $this->price_suffix,
                'currency' => 'USD',
            ],

            // The "As low as $X" figure a card leads with — the same rule and
            // the same shared trait packages use, because one item must not
            // quote two numbers on two screens. Emitted only when `plans` is
            // loaded, and SILENTLY OMITTED otherwise: a card then falls back to
            // `price.effective`, which is right today (no product has a monthly
            // plan, so the own price always wins) and would quietly stop being
            // right the day one does. Every route that serializes a product for
            // a card loads the relation; the tests assert the key is POPULATED
            // rather than merely present, because that is the difference a
            // missing load actually makes.
            'price_from' => $this->when(
                $this->relationLoaded('plans'),
                fn () => $this->catalogPriceFrom(
                    $this->plans,
                    $this->sale_price !== null || $this->retail_price !== null
                        ? (float) ($this->sale_price ?? $this->retail_price)
                        : null,
                    $this->price_suffix,
                )
            ),
            // THE LISTING LOADS PLANS BUT MUST NOT SHIP THEM. The relation is
            // eager-loaded on every product route so `price_from` above can be
            // computed, but a listing card needs the FIGURE, not the plan
            // objects — `ProductPlansTest::test_product_index_does_not_expose_plans`
            // is the contract, and gating on `whenLoaded` alone would have
            // silently broken it the moment the load was added for the figure.
            // Route-gated, like `related` and `pairs_with` above.
            'plans' => $this->when(
                $request->routeIs('api.v1.catalog.products.show') && $this->relationLoaded('plans'),
                fn () => PlanResource::collection($this->plans)->toArray($request)
            ),
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
            'seo' => $this->when($request->routeIs('api.v1.catalog.products.show'), [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'og_image_url' => $this->og_image_path ? Storage::disk('public')->url($this->og_image_path) : null,
            ]),
            'provider' => [
                'product_id' => $this->provider_product_id,
                'product_sku' => $this->provider_product_sku,
                'encounter_type_id' => $this->provider_encounter_type_id,
            ],
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }

    /**
     * Maps a loaded lookup model (class/type/form/method) to {id, name, slug} or null.
     *
     * @return array{id: int, name: string, slug: string|null}|null
     */
    private function lookup(mixed $model): ?array
    {
        if (! $model instanceof Model) {
            return null;
        }

        return [
            'id' => $model->id,
            'name' => $model->name,
            'slug' => $model->slug ?? null,
        ];
    }
}
