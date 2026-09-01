<?php

namespace App\Models\Catalog;

use Database\Factories\Catalog\ProductClassFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class ProductClass extends Model implements Sortable
{
    use HasFactory, HasSlug, SoftDeletes, SortableTrait;

    protected $fillable = [
        'name',
        'slug',
        'short_name',
        'description',
        'icon',
        'is_active',
        'position',
        'provider_product_class_id',
        'provider_product_class_slug',
    ];

    /** @var array<string, mixed> */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate()
            ->preventOverwrite();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function productTypes(): HasMany
    {
        return $this->hasMany(ProductType::class)->orderBy('position');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): ProductClassFactory
    {
        return ProductClassFactory::new();
    }
}
