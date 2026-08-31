<?php

namespace App\Models\Concerns;

use App\Enums\CatalogRelationType;
use App\Enums\CatalogStatus;
use App\Models\Catalog\CatalogRelation;
use App\Models\Catalog\Package;
use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Gives a catalog model (Product, Package) typed outgoing relations to
 * other catalog items via the double-polymorphic catalog_relations table.
 */
trait HasCatalogRelations
{
    public function catalogRelations(): MorphMany
    {
        return $this->morphMany(CatalogRelation::class, 'source')->orderBy('position');
    }

    /** @return Collection<int, Model> Related catalog items (products/packages), published only. */
    public function relatedItems(): Collection
    {
        return $this->resolveRelationTargets(CatalogRelationType::Related);
    }

    /** @return Collection<int, Model> "Pairs well with" catalog items, published only. */
    public function pairsWithItems(): Collection
    {
        return $this->resolveRelationTargets(CatalogRelationType::PairsWith);
    }

    /** @return Collection<int, Model> */
    private function resolveRelationTargets(CatalogRelationType $type): Collection
    {
        return $this->catalogRelations()
            ->where('relation_type', $type->value)
            ->with(['related' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Package::class => [
                    'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
                    // Badges: the package's own override, else derived from
                    // the products it contains. Both edges or rail cards
                    // render bare.
                    'healthGoals',
                    'healthGoalSourceProducts.healthGoals',
                ],
                Product::class => [
                    'healthGoals',
                    // A product carries `price_from` too, and the resource
                    // omits it silently without this — so a rail card would
                    // fall back to the own price while the product's own page
                    // quoted a cheaper monthly plan. Same constraint as the
                    // package branch above so the two cannot compute from
                    // different plan sets.
                    'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
                ],
            ])])
            ->get()
            ->pluck('related')
            ->filter(fn ($item) => $item !== null && $item->isPublished())
            ->values();
    }
}
