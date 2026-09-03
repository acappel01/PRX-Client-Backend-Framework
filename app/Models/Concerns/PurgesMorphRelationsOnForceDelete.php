<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Clears polymorphic pivot rows when a record is permanently deleted.
 *
 * WHY IT IS NEEDED AT ALL. Ordinary pivots are protected by a foreign key with
 * ON DELETE CASCADE, so the database cleans them up. A polymorphic pivot cannot
 * have one — the `*_type` column means the target table is not known at schema
 * time — so those rows survive their record with nothing to remove them.
 *
 * WHY THAT IS DANGEROUS RATHER THAN MERELY UNTIDY. An orphan row keyed on
 * `product #8` belongs to whatever record next occupies id 8 — its categories,
 * tags, FAQs and related-product rails reappearing on an unrelated record with
 * nothing in the admin to explain it.
 *
 * A normal insert will not reissue that id: InnoDB persists the auto-increment
 * counter across restarts from MySQL 8. The routes that DO write an explicit id
 * are the real exposure, and this project uses all of them — content fill
 * scripts, the compound import, and a database restore.
 *
 * FORCE DELETE ONLY, DELIBERATELY. A soft delete is reversible, and a restored
 * record must come back with its classifications intact — purging on the soft
 * delete would make every restore silently lossy. This is the same rule the
 * database already follows for the foreign-keyed pivots, which likewise do not
 * cascade on a soft delete because no row is removed. `forceDeleted` fires only
 * on the permanent delete, where the record genuinely cannot return.
 *
 * Declare the pivots on the model:
 *
 *     protected array $morphPivots = [
 *         ['table' => 'categorizables', 'morph' => 'categorizable'],
 *     ];
 */
trait PurgesMorphRelationsOnForceDelete
{
    public static function bootPurgesMorphRelationsOnForceDelete(): void
    {
        // Models without SoftDeletes never fire this, and they do not need to:
        // their `deleted` IS the permanent delete. Guarding here keeps the
        // trait safe to apply either way.
        if (! method_exists(static::class, 'forceDeleted')) {
            return;
        }

        static::forceDeleted(function ($model): void {
            foreach ($model->morphPivots() as $pivot) {
                DB::table($pivot['table'])
                    ->where($pivot['morph'].'_type', $model->getMorphClass())
                    ->where($pivot['morph'].'_id', $model->getKey())
                    ->delete();
            }
        });
    }

    /** @return array<int, array{table: string, morph: string}> */
    public function morphPivots(): array
    {
        return $this->morphPivots ?? [];
    }
}
