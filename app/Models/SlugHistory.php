<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A slug a record used to answer to.
 *
 * Rows are written by App\Models\Concerns\HasSlugHistory and read by exactly
 * one place — the public slug-resolution endpoint — after a live lookup has
 * already missed. Nothing else should query this table: a historical slug is
 * not an identifier, it is a redirect source.
 */
class SlugHistory extends Model
{
    protected $fillable = ['subject_type', 'subject_id', 'slug'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
