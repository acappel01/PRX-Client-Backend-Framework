<?php

namespace App\Models\Kb;

use App\Models\Concerns\HasSlugHistory;
use App\Models\Catalog\Ingredient;
use App\Models\Catalog\Package;
use App\Models\Catalog\Product;
use App\Models\User;
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

/**
 * What a visitor says they want, and the hinge the whole recommendation flow
 * turns on.
 *
 * Two edges hang off a goal and they do different jobs:
 *
 * - `ingredients()` RECOMMENDS. Weighted, and resolved through the catalog —
 *   an ingredient is what a product actually contains, so a recommendation
 *   derived from it cannot point at something this install does not sell.
 *   Packages are never mapped directly; a stack surfaces because it contains a
 *   product containing a matching ingredient, which is why it cannot drift out
 *   of step with its own contents.
 * - `compounds()` EDUCATES. It answers "which goals does this peptide align
 *   with" on a knowledge-base page, and it exists separately because only 7 of
 *   102 compounds map to a catalog ingredient — deriving the KB's goals from
 *   the commercial edge would leave 95 monographs showing none at all.
 *
 * `is_active` and `show_in_quiz` are separate on purpose. Withdrawing a goal
 * from intake should not unpick the mappings that already reference it, and an
 * operator retiring a goal usually means the former rather than the latter.
 */
class HealthGoal extends Model implements Sortable
{
    use HasFactory, HasSlug, HasSlugHistory, SoftDeletes;

    // Aliased rather than overridden: SortableTrait is a trait, so `parent::`
    // reaches Model and not the implementation being wrapped.
    use SortableTrait {
        setHighestOrderNumber as private appendToEndOfOrder;
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate()
            ->preventOverwrite();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected $fillable = [
        'name',
        'slug',
        'prompt',
        'description',
        'icon',
        'icon_size',
        'color',
        'badge_color',
        'image_path',
        'parent_id',
        'is_active',
        'show_in_quiz',
        'position',
        'meta_title',
        'meta_description',
        'created_by',
        'updated_by',
    ];

    /** @var array<string, mixed> */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_in_quiz' => 'boolean',
        ];
    }

    /**
     * Respects a position that was set explicitly, instead of always appending.
     *
     * Spatie's `setHighestOrderNumber()` assigns unconditionally on create, so
     * a seeder or import passing `position` silently gets creation order
     * instead. Product and Package live with that because their position is
     * only ever set by dragging rows; a goal's position IS the order the quiz
     * offers it in, which is exactly the thing someone writes down.
     *
     * Divergence from the catalog models, on purpose — but only in the case
     * they never hit: with no position supplied, this appends exactly as they do.
     */
    public function setHighestOrderNumber(): void
    {
        if ($this->position !== null && $this->position > 0) {
            return;
        }

        $this->appendToEndOfOrder();
    }

    /** Active goals only — the baseline every public read starts from. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** What the intake quiz may offer. Narrower than active, deliberately. */
    public function scopeForQuiz(Builder $query): Builder
    {
        return $query->active()->where('show_in_quiz', true);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * The recommendation edge. Pivot carries relevance_weight (0-100),
     * evidence_level, is_first_line and relevance_note.
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'health_goal_ingredient')
            ->withPivot(['relevance_weight', 'evidence_level', 'is_first_line', 'relevance_note', 'position'])
            ->withTimestamps()
            ->orderByPivot('relevance_weight', 'desc');
    }

    /**
     * The direct override — a product pinned to a goal whatever its
     * ingredients say.
     *
     * THIS EDGE NOW HAS A SECOND JOB. It was built as a recommendation
     * override, on the reasoning that pinning products by hand means the
     * ingredient mappings are what is actually missing. Health-goal badges
     * (the chips a storefront shows on a product card) read the same edge,
     * deliberately: the quiz and the badges naming the same goal is the whole
     * point, and a second product-to-goal vocabulary would guarantee they
     * drift apart.
     *
     * The consequence to watch: badge tagging means most products WILL be
     * pinned here, at the default relevance weight. GoalRecommendationResolver
     * resolves through health_goal_ingredient and does not read this edge
     * today. If it ever starts to, every badge becomes a recommendation
     * override overnight — split the two with a pivot flag before that lands,
     * not after.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'health_goal_product')
            ->withPivot(['relevance_weight', 'is_first_line', 'relevance_note', 'position'])
            ->withTimestamps()
            ->orderByPivot('relevance_weight', 'desc');
    }

    /**
     * The badge OVERRIDE for packages — display only.
     *
     * A package's badges are normally derived from the goals of the products
     * inside it, so tagging a product once updates every stack containing it
     * and a stack cannot claim something its contents do not. Rows here exist
     * for the one case derivation cannot express: a stack marketed for a
     * single goal, which must show that goal ALONE rather than the union of
     * everything its parts treat. When this relation has rows they REPLACE the
     * derived set; they never add to it.
     *
     * Never read this for recommendations. Packages are not mapped directly to
     * goals for resolution — see GoalRecommendationResolver — and this table
     * does not change that.
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'health_goal_package')
            ->withPivot(['position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /** The education edge — what the knowledge base shows on a monograph. */
    public function compounds(): BelongsToMany
    {
        return $this->belongsToMany(Compound::class, 'compound_health_goal')
            ->withPivot(['relevance_note', 'evidence_level', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
