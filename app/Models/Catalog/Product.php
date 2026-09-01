<?php

namespace App\Models\Catalog;

use App\Enums\Catalog\IntakeSelectionMode;
use App\Enums\CatalogStatus;
use App\Enums\InventoryStatus;
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
use Database\Factories\Catalog\ProductFactory;
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

class Product extends Model implements Sortable
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
        'product_class_id',
        'product_type_id',
        'product_form_id',
        'administration_method_id',
        'volume',
        'volume_unit_id',
        'inventory_status',
        'is_controlled_substance',
        'rx_required',
        'retail_price',
        'sale_price',
        'cost',
        'price_suffix',
        'provider_product_id',
        'provider_product_sku',
        'provider_encounter_type_id',
        'intake_selection_mode',
        'badge_text',
        'highlights',
        'detail_sections',
        'detail_layout',
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
     * The card figure as SQL — see `HasCardPriceExpression`.
     *
     * A product's own price is its figure today, because no product carries a
     * monthly plan to undercut it — so this changes nothing visible. It exists
     * so that when one does, the filter, the sort and the facet bounds follow
     * the cards instead of quietly disagreeing with them, which is exactly the
     * defect packages had on /stacks.
     */
    public static function priceFromAmountSql(): string
    {
        return static::cardPriceExpression('products', 'product_id');
    }

    /**
     * Mirrors the column default so a NEW model instance reports the same
     * selection mode as a saved one. Without it the attribute reads null
     * until the row is refreshed, and any `=== IntakeSelectionMode::…`
     * comparison silently takes the wrong branch on an unrefreshed object.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'intake_selection_mode' => 'product',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'inventory_status' => InventoryStatus::class,
            'intake_selection_mode' => IntakeSelectionMode::class,
            'gallery' => 'array',
            'retail_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'volume' => 'decimal:4',
            'is_featured' => 'boolean',
            'is_in_stock' => 'boolean',
            'is_controlled_substance' => 'boolean',
            'rx_required' => 'boolean',
            'requires_lab' => 'boolean',
            'highlights' => 'array',
            'detail_sections' => 'array',
            'detail_layout' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // inventory_status is the source of truth when set; is_in_stock stays
        // the storefront-facing boolean the API and filters already consume.
        static::saving(function (Product $product): void {
            if ($product->inventory_status !== null) {
                $product->is_in_stock = $product->inventory_status->isPurchasable();
            }
        });
    }

    /**
     * Health goals this product is pinned to directly.
     *
     * The override, not the main path. Most recommendations reach a product
     * through its INGREDIENTS, which is what the product actually contains;
     * this edge exists for the case where an operator wants a product on a
     * goal regardless of that.
     */
    public function healthGoals(): BelongsToMany
    {
        return $this->belongsToMany(HealthGoal::class, 'health_goal_product')
            ->withPivot(['relevance_weight', 'is_first_line', 'relevance_note', 'position'])
            ->withTimestamps();
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_product')
            ->withPivot('sort_order', 'is_included')
            ->orderByPivot('sort_order');
    }

    public function productClass(): BelongsTo
    {
        return $this->belongsTo(ProductClass::class);
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function productForm(): BelongsTo
    {
        return $this->belongsTo(ProductForm::class);
    }

    public function administrationMethod(): BelongsTo
    {
        return $this->belongsTo(AdministrationMethod::class);
    }

    public function volumeUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class, 'volume_unit_id');
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class)
            ->using(IngredientProduct::class)
            ->withPivot([
                'concentration',
                'concentration_unit_id',
                'per_volume',
                'per_volume_unit_id',
                'provider_quantity_label',
                'position',
            ])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function coas(): HasMany
    {
        return $this->hasMany(ProductCoa::class)->orderByDesc('issued_at')->orderByDesc('id');
    }

    /** Product term plans (3/6/9/12-month pricing) — same Plan machinery packages use. */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderBy('position');
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
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
