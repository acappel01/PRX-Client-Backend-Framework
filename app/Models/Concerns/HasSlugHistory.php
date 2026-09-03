<?php

namespace App\Models\Concerns;

use App\Models\SlugHistory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Remembers the slugs a record used to have, so its old URLs can redirect.
 *
 * Apply to any model whose slug is part of a public URL. The frontend keeps no
 * registry of valid slugs — it asks the API and 404s on a miss — so without
 * this, renaming a record silently breaks every link anyone already has.
 *
 * THE HOOK IS `updated`, NOT `saving`. It has to run after the write, because
 * that is when getOriginal() still holds the pre-save value while the model
 * holds the new one; on `saving` the "original" is whatever the last refresh
 * loaded and may already equal the new value.
 *
 * RECLAIMING A SLUG IS A REAL CASE AND IT WINS, ON CREATE AS WELL AS RENAME.
 * If a record takes a slug some other record abandoned, the stale history row
 * is deleted rather than left to fight the live lookup. Doing this only on
 * rename looked sufficient — a live record shadows history, so the stale row is
 * invisible while the reclaimer exists — but it is not: soft-delete the
 * reclaimer and the row comes back to life, redirecting a DELETED record's URL
 * to a DIFFERENT record. Hence a `created` hook as well.
 *
 * DELETING A RECORD DELETES ITS HISTORY, including on a soft delete. A
 * soft-deleted product is unreachable, so its old URLs must 404 rather than
 * redirect to a page that will itself 404 — one dead end is better than two.
 */
trait HasSlugHistory
{
    public static function bootHasSlugHistory(): void
    {
        // A brand-new record may be claiming a name some other record gave up.
        // There is no previous slug to record, only a stale row to clear.
        static::created(function ($model): void {
            static::forgetSlugHistory($model, $model->slug);
        });

        static::updated(function ($model): void {
            $previous = $model->getOriginal('slug');

            if (blank($previous) || $previous === $model->slug) {
                return;
            }

            // The new name may itself be a slug this record — or another —
            // used before. Its history row is now wrong either way.
            static::forgetSlugHistory($model, $model->slug);

            SlugHistory::query()->updateOrCreate(
                ['subject_type' => $model->getMorphClass(), 'slug' => $previous],
                ['subject_id' => $model->getKey()],
            );
        });

        static::deleted(function ($model): void {
            // For a soft-deleting model this fires on the SOFT delete, which
            // should keep the rows — the record may come back. Models without
            // SoftDeletes never fire forceDeleted, so for them this IS the
            // permanent delete and must clean up.
            if (! method_exists($model, 'trashed')) {
                $model->slugHistories()->delete();
            }
        });

        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(function ($model): void {
                $model->slugHistories()->delete();
            });
        }
    }

    /** Drop any history row claiming `$slug` for this model's type. */
    private static function forgetSlugHistory($model, ?string $slug): void
    {
        if (blank($slug)) {
            return;
        }

        SlugHistory::query()
            ->where('subject_type', $model->getMorphClass())
            ->where('slug', $slug)
            ->delete();
    }

    public function slugHistories(): MorphMany
    {
        return $this->morphMany(SlugHistory::class, 'subject');
    }
}
