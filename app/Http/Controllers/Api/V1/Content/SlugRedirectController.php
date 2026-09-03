<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Http\Controllers\Controller;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogPost;
use App\Models\Catalog\Category;
use App\Models\Catalog\Package;
use App\Models\Catalog\Product;
use App\Models\Kb\Compound;
use App\Models\Kb\HealthGoal;
use App\Models\Page;
use App\Models\SlugHistory;
use Illuminate\Http\JsonResponse;
use App\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Where did this slug go?
 *
 * The frontend asks only after its own lookup has already 404'd, so this is a
 * miss handler, never part of a normal page render. It answers with the entity
 * and its CURRENT slug — deliberately not a URL. The frontend owns URL
 * structure (its routes map {type, slug} to a path), and a backend that
 * returned "/products/x" would be dictating a path it cannot know is right for
 * every frontend this package ships to.
 *
 * A LIVE SLUG ALWAYS WINS, and that ordering is the safety property. Each type
 * is checked against live records first: if some record has since taken the old
 * name, it serves its own page and no redirect is offered. History can never
 * shadow the present, which is what stops a stale row hijacking a live URL.
 *
 * ONE HOP, ALWAYS. History rows point at the RECORD, not at a successor slug,
 * so a -> b -> c resolves straight to c rather than walking a chain.
 */
class SlugRedirectController extends Controller
{
    /**
     * Public URL types this deployment can redirect, mapped to their models.
     *
     * The key is the vocabulary the frontend already speaks — the same `type`
     * that link fields and menu items carry — so the answer needs no
     * translation on arrival.
     */
    private const TYPES = [
        'product' => Product::class,
        'package' => Package::class,
        'page' => Page::class,
        'kb_compound' => Compound::class,
        'catalog_category' => Category::class,
        'blog_post' => BlogPost::class,
        'blog_category' => BlogCategory::class,
        'health_goal' => HealthGoal::class,
    ];

    /**
     * Is this record something the public can actually reach?
     *
     * REQUIRED, and the failure without it is worse than no redirect at all.
     * Drafting a record and renaming it in one save would otherwise send its old
     * URL to a new URL that 404s — two dead ends instead of one — while
     * disclosing the current name of an unreleased record in a Location header
     * to anyone holding an old link.
     *
     * Each rule MIRRORS that type's public detail endpoint. They genuinely
     * differ (status enum, a published() scope, a visibility flag), so they are
     * spelled out here rather than guessed at; the per-type test asserting an
     * unpublished target does not resolve is what stops this drifting from the
     * endpoints it mirrors.
     */
    private function isPubliclyVisible(Model $subject): bool
    {
        return match (true) {
            $subject instanceof Product,
            $subject instanceof Package => $subject->status === CatalogStatus::Published,

            $subject instanceof Page => Page::published()->whereKey($subject->getKey())->exists(),

            $subject instanceof Compound => Compound::published()->whereKey($subject->getKey())->exists(),

            $subject instanceof BlogPost => BlogPost::published()->whereKey($subject->getKey())->exists(),

            $subject instanceof Category,
            $subject instanceof BlogCategory => (bool) $subject->is_visible,

            // A goal drives a public `?goal=` filter, so "reachable" here means
            // active. `show_in_quiz` is deliberately not consulted: it decides
            // only whether the goal is OFFERED in the quiz, not whether it
            // classifies the catalog.
            $subject instanceof HealthGoal => (bool) $subject->is_active,

            // An unregistered type cannot reach here, but defaulting to "not
            // visible" means a future type added to TYPES without a rule fails
            // closed — no redirect — rather than leaking a draft.
            default => false,
        };
    }

    // The keys above are each model's morph class, which is also the `type` a
    // frontend already receives on link fields and menu items — so an answer
    // needs no translation, and a new type cannot drift from the vocabulary
    // the rest of the API speaks.

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(self::TYPES))],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $model = self::TYPES[$data['type']];

        // If anything live answers to this slug there is nothing to redirect:
        // the caller's own lookup should have found it, and if it did not, the
        // record is unpublished — which is a 404, not a redirect to itself.
        if ($model::query()->where('slug', $data['slug'])->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $history = SlugHistory::query()
            ->where('subject_type', (new $model)->getMorphClass())
            ->where('slug', $data['slug'])
            ->first();

        $subject = $history?->subject;

        // No history, or the record it pointed at is gone. A soft-deleted
        // record resolves to null here because its morphTo respects the
        // model's global scopes — which is what we want: redirecting to a page
        // that will itself 404 helps nobody.
        if ($subject === null || blank($subject->slug) || ! $this->isPubliclyVisible($subject)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'data' => [
                'type' => $data['type'],
                'slug' => $subject->slug,
            ],
        ]);
    }
}
