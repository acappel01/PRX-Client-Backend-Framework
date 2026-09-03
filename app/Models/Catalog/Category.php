<?php

namespace App\Models\Catalog;

use App\Models\Concerns\HasSlugHistory;
use App\Models\Concerns\GeneratesUniqueSlug;
use Database\Factories\Catalog\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Category extends Model implements Sortable
{
    use GeneratesUniqueSlug, HasFactory, HasSlugHistory, SoftDeletes, SortableTrait;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'provider_encounter_type_id',
        'description',
        'hero_image_path',
        'icon',
        'is_visible',
        'position',
        'meta_title',
        'meta_description',
    ];

    /** @var array<string, mixed> */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'categorizable')
            ->withPivot('position')
            ->orderBy('categorizables.position');
    }

    public function packages(): MorphToMany
    {
        return $this->morphedByMany(Package::class, 'categorizable')
            ->withPivot('position')
            ->orderBy('categorizables.position');
    }

    public function plans(): MorphToMany
    {
        return $this->morphedByMany(Plan::class, 'categorizable')
            ->withPivot('position')
            ->orderBy('categorizables.position');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
