<?php

namespace App\Services\Cms;

use App\Jobs\Cms\RevalidateFrontendJob;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\Content\FaqCategory;
use App\Models\Content\FaqItem;
use App\Models\Kb\Compound;
use App\Models\Catalog\Package;
use App\Models\Catalog\Product;
use App\Models\Catalog\Plan;
use App\Models\Kb\HealthGoal;
use App\Models\Quiz\Quiz;
use App\Models\Quiz\QuizQuestion;
use App\Models\Quiz\QuizQuestionOption;
use App\Models\Quiz\QuizStep;
use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;

/**
 * Translates a CMS write into the cache tags a decoupled frontend must
 * purge, and ships them once per request.
 *
 * Tags, not URLs: this app names entities (`page:faq`) and the frontend owns
 * the routes those entities live at. Sending paths would couple the backend
 * to one frontend's routing.
 *
 * Registered as a singleton so a single admin save — which fires Page::saved
 * plus one PageSection::saved per section — coalesces into ONE queued job
 * instead of a dozen. Tags accumulate during the request and flush on
 * terminate.
 */
class FrontendRevalidator
{
    /** Purges every content payload; used when a write's blast radius is broad. */
    private const TAG_ALL = 'cms';

    /** @var array<string, true> */
    private array $tags = [];

    private bool $flushRegistered = false;

    public function __construct(private readonly Application $app) {}

    public function enabled(): bool
    {
        return filled(config('cms.frontend.revalidate_url'))
            && filled(config('cms.frontend.revalidate_secret'));
    }

    /**
     * Queue the tags for a model that was just written.
     */
    public function modelChanged(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach ($this->tagsFor($model) as $tag) {
            $this->tags[$tag] = true;
        }

        $this->registerFlush();
    }

    /**
     * Queue tags for a write that is NOT an Eloquent model.
     *
     * Settings are the reason this exists: they live in the `settings` table
     * as a spatie payload, never pass through an observer, and so were
     * invisible to modelChanged(). Every settings save cleared the backend's
     * own `api.v1.config` cache and told the frontend nothing, which left a
     * palette or brand edit waiting out the full ISR window while the admin
     * insisted it had saved.
     *
     * Prefer ConfigCache::invalidate() over calling this directly for config
     * writes — it pairs this with the backend cache clear so the two cannot
     * drift apart again.
     */
    public function tagsChanged(string ...$tags): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach ($tags as $tag) {
            if (filled($tag)) {
                $this->tags[$tag] = true;
            }
        }

        $this->registerFlush();
    }

    /**
     * Send whatever has accumulated. Safe to call directly (tests, commands).
     */
    public function flush(): void
    {
        if ($this->tags === [] || ! $this->enabled()) {
            return;
        }

        $tags = array_keys($this->tags);
        $this->tags = [];

        RevalidateFrontendJob::dispatch($tags);
    }

    /**
     * Cache tags a write to this model invalidates.
     *
     * Anything whose blast radius isn't a single addressable entity falls
     * back to TAG_ALL — a global section or a flexible type can appear on
     * any page, and FAQ rows are inlined into cached page payloads by the
     * faq-categories section. Over-purging is cheap; under-purging shows
     * operators stale content.
     *
     * @return list<string>
     */
    /**
     * Detail tags for a model with a public url: the slug it has now, and the
     * slug it had a moment ago if this save changed it.
     *
     * Both are required. Tagging only the new slug refreshes a page nobody has
     * visited yet while leaving the old url serving the record under its former
     * name; tagging only the old one never picks up the rename.
     *
     * getOriginal() is read AFTER the write, which is exactly when it still
     * holds the pre-save value — that is the whole reason this works from an
     * observer's saved() hook rather than needing an updating() hook.
     */
    private function slugTags(Model $model, string $prefix): array
    {
        $tags = [];

        if (filled($model->slug)) {
            $tags[] = $prefix.':'.$model->slug;
        }

        $original = $model->getOriginal('slug');

        if (filled($original) && $original !== $model->slug) {
            $tags[] = $prefix.':'.$original;
        }

        return $tags;
    }

    private function tagsFor(Model $model): array
    {
        return match (true) {
            $model instanceof Page => array_values(array_filter([
                self::TAG_ALL,
                $model->slug ? 'page:'.$model->slug : null,
            ])),

            $model instanceof PageSection => array_values(array_filter([
                self::TAG_ALL,
                ($slug = $model->page?->slug) ? 'page:'.$slug : null,
            ])),

            $model instanceof Menu => array_values(array_filter([
                self::TAG_ALL,
                'layout',
                $model->slug ? 'menu:'.$model->slug : null,
            ])),

            $model instanceof MenuItem => array_values(array_filter([
                self::TAG_ALL,
                'layout',
                ($menuSlug = $model->menu?->slug) ? 'menu:'.$menuSlug : null,
            ])),

            $model instanceof FaqCategory,
            $model instanceof FaqItem => [self::TAG_ALL],

            // `kb` is the broad tag the index page carries; `kb:{slug}` is the
            // monograph itself. Both are sent because publishing a compound
            // changes the listing as well as the detail page, and the frontend
            // caches them under separate tags.
            // The quiz reads one list of goals, so a change to any of them
            // invalidates that list; there is no per-goal page to tag.
            $model instanceof HealthGoal => [self::TAG_ALL, 'health-goals'],

            // The quiz payload embeds LIVE price ranges computed from plans,
            // so a catalog write can make a cached quiz wrong without the quiz
            // itself being touched. Plans and packages therefore invalidate
            // `quiz` too — a plan's price, and a package's tier, both decide
            // which range an option shows.
            $model instanceof Plan => [self::TAG_ALL, 'quiz'],

            // Products and packages carry a PUBLIC URL, so they need the
            // catalog listing tag and their own detail tag as well.
            //
            // `slugTags()` is what makes a RENAME work. The observer fires on
            // saved(), so $model->slug is already the NEW value and tagging
            // only that leaves the OLD url's cached render untouched — and a
            // stale render is not merely stale here, it is unkillable: the
            // frontend re-validates it on every request, the upstream answers
            // 404, and notFound() during regeneration does not replace the
            // cached 200. Measured on this deployment: a renamed product served
            // its old url indefinitely, and a tag purge cleared it instantly.
            $model instanceof Product => array_values(array_filter([
                self::TAG_ALL,
                'catalog',
                ...$this->slugTags($model, 'product'),
            ])),

            $model instanceof Package => array_values(array_filter([
                self::TAG_ALL,
                'catalog',
                'quiz',
                ...$this->slugTags($model, 'package'),
            ])),

            $model instanceof Quiz,
            $model instanceof QuizStep,
            $model instanceof QuizQuestion,
            $model instanceof QuizQuestionOption => [self::TAG_ALL, 'quiz'],

            $model instanceof Compound => array_values(array_filter([
                self::TAG_ALL,
                'kb',
                $model->slug ? 'kb:'.$model->slug : null,
            ])),

            default => [self::TAG_ALL],
        };
    }

    /**
     * Flush at end of request so one save sends one job. Registered lazily —
     * a request that touches no CMS content registers no callback.
     *
     * Two hooks, because neither covers everything:
     *   - terminating() fires after an HTTP response and after each queued
     *     job, which is what a long-running Horizon worker needs (a shutdown
     *     hook there would hold tags until the worker itself stopped).
     *   - register_shutdown_function() covers `artisan tinker`, which exits
     *     without running terminating callbacks — that is the path the
     *     deployment fill scripts use, and they would otherwise never
     *     revalidate.
     *
     * flush() empties the tag set, so whichever fires first wins and the
     * other is a no-op. Registering both is safe.
     */
    private function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;

        $this->app->terminating(function (): void {
            $this->flushRegistered = false;
            $this->flush();
        });

        // Console only, and never under test: by the time a shutdown handler
        // runs the container may already be torn down, and dispatching needs
        // it. Tests reset the app between cases, so this would fatal at the
        // end of the run; they exercise flush() directly instead.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            register_shutdown_function(function (): void {
                $this->flushRegistered = false;

                try {
                    $this->flush();
                } catch (\Throwable $e) {
                    // Losing a revalidation is survivable — the frontend's own
                    // TTL still expires. Killing the process at shutdown is not.
                    report($e);
                }
            });
        }
    }
}
