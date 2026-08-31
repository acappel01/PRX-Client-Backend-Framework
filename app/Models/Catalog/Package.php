<?php

namespace App\Models\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Concerns\HasCardPriceExpression;
use App\Models\Concerns\HasCatalogRelations;
use App\Models\Concerns\HasCategories;
use App\Models\Concerns\HasFaqs;
use App\Models\Concerns\HasFulfillmentCenter;
use App\Models\Concerns\HasItemSections;
use App\Models\Concerns\HasReviews;
use App\Models\Concerns\HasTags;
use App\Models\Kb\HealthGoal;
use App\Models\User;
use Database\Factories\Catalog\PackageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Package extends Model implements Sortable
{
    use HasCardPriceExpression, HasCatalogRelations, HasCategories, HasFactory, HasFaqs, HasFulfillmentCenter, HasItemSections, HasReviews, HasSlug, HasTags, SoftDeletes, SortableTrait;

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate()
            ->preventOverwrite();
    }

    protected $fillable = [
        'name',
        'slug',
        'subtitle',
        'short_description',
        'description',
        'hero_image_path',
        'gallery',
        'status',
        'tier',
        'retail_price',
        'sale_price',
        'cost',
        'price_suffix',
        'provider_package_id',
        'provider_package_sku',
        'provider_encounter_type_id',
        'badge_text',
        'highlights',
        'detail_sections',
        'detail_layout',
        'banner_image_path',
        'is_featured',
        'is_in_stock',
        'requires_lab',
        'meta_title',
        'meta_description',
        'og_image_path',
        'position',
        'default_fulfillment_center_id',
        'last_synced_at',
        'created_by',
        'updated_by',
    ];

    /** @var array<string, mixed> */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    /**
     * The card figure as SQL — see `HasCardPriceExpression` for the rule, the
     * debt it represents, and the guards that keep it honest.
     */
    public static function priceFromAmountSql(): string
    {
        return static::cardPriceExpression('packages', 'package_id');
    }

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'gallery' => 'array',
            'retail_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_featured' => 'boolean',
            'is_in_stock' => 'boolean',
            'requires_lab' => 'boolean',
            'highlights' => 'array',
            'detail_sections' => 'array',
            'detail_layout' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'package_product')
            ->withPivot('sort_order', 'is_included')
            ->orderByPivot('sort_order');
    }

    /**
     * The products badge derivation reads, and NOTHING else.
     *
     * A separate relation from products() for two reasons, both learned the
     * hard way:
     *
     * 1. PackageResource serializes `whenLoaded('products')`. Eager-loading
     *    products on the LISTING purely to derive badges therefore switched on
     *    a full nested ProductResource payload for every package in the index —
     *    a public contract change nobody asked for, carrying SKUs and flags the
     *    listing had never served.
     * 2. products() carries no status constraint. The show route filters to
     *    published explicitly; the index did not, so a draft product attached
     *    to a published stack would have had its name, slug and price served
     *    to any visitor, and would have badged a card whose detail page showed
     *    no such badge.
     *
     * Constraining it here means every derivation path agrees, rather than
     * each caller remembering to filter.
     */
    public function healthGoalSourceProducts(): BelongsToMany
    {
        return $this->products()->where('products.status', CatalogStatus::Published);
    }

    /**
     * Badge OVERRIDE only — see HealthGoal::packages() for why this is not the
     * source of truth. Empty (the normal case) means the storefront shows the
     * union of the contained products' goals; rows here REPLACE that set.
     */
    public function healthGoals(): BelongsToMany
    {
        return $this->belongsToMany(HealthGoal::class, 'health_goal_package')
            ->withPivot(['position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    protected static function newFactory(): PackageFactory
    {
        return PackageFactory::new();
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === CatalogStatus::Published;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
