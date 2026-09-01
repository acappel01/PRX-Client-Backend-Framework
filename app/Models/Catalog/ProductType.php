<?php

namespace App\Models\Catalog;

use Database\Factories\Catalog\ProductTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class ProductType extends Model implements Sortable
{
    use HasFactory, HasSlug, SoftDeletes, SortableTrait;

    protected $fillable = [
        'product_class_id',
        'name',
        'slug',
        'short_name',
        'description',
        'is_active',
        'position',
        'provider_product_type_id',
        'provider_product_type_slug',
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

    public function productClass(): BelongsTo
    {
        return $this->belongsTo(ProductClass::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): ProductTypeFactory
    {
        return ProductTypeFactory::new();
    }
}
