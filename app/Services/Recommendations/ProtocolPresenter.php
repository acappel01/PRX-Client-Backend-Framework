<?php

namespace App\Services\Recommendations;

use App\Enums\CatalogStatus;
use App\Http\Resources\Api\V1\Catalog\PackageResource;
use App\Http\Resources\Api\V1\Catalog\ProductResource;
use App\Models\Kb\HealthGoal;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * One goal's protocol, serialized for the API.
 *
 * EXTRACTED SO THE TWO SURFACES CANNOT DRIFT. The quiz asks this question
 * twice: once live, as the visitor picks goals (`POST /protocol/preview`), and
 * once afterwards, when their saved plan is rendered (`GET /leads/{uuid}/plan`).
 * Those are the same answer about the same person and must be the same shape —
 * if the preview says a goal is `restricted` and the report renders it as
 * `unmapped`, the visitor is told two different stories about why they were
 * shown nothing. This project has shipped that class of bug three times in one
 * change by fixing the surfaces it happened to list; the fix is to have one
 * producer, so a new consumer inherits the rule instead of re-implementing it.
 *
 * Eager loads live here rather than at the call sites for the same reason: the
 * published-only constraint on nested products is a content-leak guard (see
 * CatalogInliner), and a second call site loading them unconstrained would
 * reopen it silently.
 */
final readonly class ProtocolPresenter
{
    public function __construct(private GoalRecommendationResolver $resolver) {}

    /**
     * @param  Collection<int, HealthGoal>  $goals
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $goals, VisitorProfile $profile, Request $request): array
    {
        return $goals
            ->map(fn (HealthGoal $goal): array => $this->presentGoal($goal, $profile, $request))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGoal(HealthGoal $goal, VisitorProfile $profile, Request $request): array
    {
        $result = $this->resolver->resolve($goal, $profile);

        $products = $result['products']->loadMissing([
            'ingredients',
            'healthGoals',
            // Products carry `price_from` as packages do, and it is omitted
            // silently without this — see the note on the package load below,
            // which is the same trap and cost this surface a wrong figure once.
            'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
        ]);
        $packages = $result['packages']->loadMissing([
            // Published-only: see CatalogInliner for why an unconstrained
            // nested products load is a content leak, not a detail.
            'products' => fn ($q) => $q->where('products.status', CatalogStatus::Published),
            'products.healthGoals',
            'healthGoals',
            'healthGoalSourceProducts.healthGoals',

            // REQUIRED FOR `price_from`, WHICH IS WHY IT IS NOT OPTIONAL HERE.
            // PackageResource emits `price_from` only when this relation is
            // loaded, and silently omits it otherwise — so without this line a
            // package renders its own price while every catalogue card renders
            // the cheaper "as low as" figure. That is the single-figure
            // disagreement the shared card price rule exists to have ended,
            // reappearing through a missing eager load rather than through a
            // second implementation.
            // Filtered and ordered exactly as the listing does it
            // (PackageController) so the two cannot compute from different
            // plan sets and disagree that way instead.
            'plans' => fn ($q) => $q->where('status', CatalogStatus::Published)->orderBy('position'),
        ]);

        return [
            'goal' => [
                'name' => $goal->name,
                'slug' => $goal->slug,
                'prompt' => $goal->prompt ?: $goal->name,
            ],
            'products' => ProductResource::collection($products)->toArray($request),
            'packages' => PackageResource::collection($packages)->toArray($request),

            // Named by the resolver, not inferred here. "restricted" and
            // "unmapped" both render zero products and need completely
            // different copy, and the distinction needs an unfiltered
            // baseline the frontend does not have — see resolve().
            'outcome' => $result['outcome'],
            'excluded_count' => $result['excluded_count'],
        ];
    }
}
