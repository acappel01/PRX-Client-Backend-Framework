<?php

namespace App\Models\Kb;

use App\Enums\Kb\RegulatoryStatus;
use App\Models\Catalog\Ingredient;
use App\Models\Concerns\HasSlugHistory;
use App\Models\Concerns\HasItemSections;
use App\Models\Content\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A knowledge-base monograph. Public at `/{kb-prefix}/{slug}` on the frontend,
 * which owns that prefix.
 *
 * Two behaviours here are load-bearing and easy to undo by accident:
 *
 * 1. **`published()` requires a regulatory status as well as the flag — and
 *    deliberately does NOT require a reviewer.**
 *
 *    The status is a warning, not a byline: when it is null the public page
 *    renders no not-approved notice and the structured data carries no
 *    `legalStatus`, so a research compound goes live reading exactly like an
 *    approved medicine. A missing status is not a blank field, it is a missing
 *    warning, and that is why it is in the query rather than in a comment.
 *
 *    A clinician reviewer was in this scope and was **removed on purpose**.
 *    This content is summarised from the operator's own clinical literature
 *    corpus by a retrieval pipeline — it is not authored by one of their
 *    providers, and requiring a provider's name before publishing ~100 pages
 *    makes the path of least resistance "attach one doctor to all of them".
 *    That produces a byline asserting a clinical review that did not happen,
 *    on medical content, which is worse than no byline at all. The field
 *    remains for the cases where someone genuinely does review a monograph.
 *
 *    Import already sets `is_published = false`, so nothing reaches the public
 *    without a deliberate per-page act. That is what stops unread drafts going
 *    live; a mandatory byline was never what did it.
 *
 * 2. **`HasSlug` uses `preventOverwrite()`,** matching Product and Package. A
 *    slug set explicitly (the import sets every one) is taken verbatim with no
 *    uniqueness suffix, so the caller owns collision handling; a slug left
 *    null is generated from the name and suffixed `-2`, `-3` on collision.
 */
class Compound extends Model implements Sortable
{
    use HasFactory, HasItemSections, HasSlug, HasSlugHistory, SoftDeletes, SortableTrait;

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
        'tagline',
        'brand_names',
        'synonyms',
        'compound_class',
        'is_peptide',
        'regulatory_status',
        'route_of_administration',
        'description',
        'overview',
        'mechanism_of_action',
        'pharmacology',
        'clinical_evidence',
        'dosing_guidelines',
        'safety_profile',
        'patient_summary',
        'clinical_references',
        'evidence_tier',
        'evidence_score',
        'ingredient_id',
        'reviewed_by_profile_id',
        'reviewed_at',
        'review_notes',
        'is_published',
        'published_at',
        'meta_title',
        'meta_description',
        'hero_image_path',
        'og_image_path',
        'source_system',
        'source_ref',
        'provider_compound_id',
        'content_model',
        'content_generated_at',
        'source_document_count',
        'source_dosing_count',
        'position',
        'last_synced_at',
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
            'regulatory_status' => RegulatoryStatus::class,
            'brand_names' => 'array',
            'synonyms' => 'array',
            'clinical_references' => 'array',
            'is_peptide' => 'boolean',
            'is_published' => 'boolean',
            'evidence_score' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'content_generated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // published_at is a display date, is_published is the gate. Keeping
        // them in step here means no form, command or API path has to remember
        // to set both, and an unpublish does not silently keep the old date.
        static::saving(function (Compound $compound): void {
            if ($compound->is_published && $compound->published_at === null) {
                $compound->published_at = now();
            }

            if (! $compound->is_published) {
                $compound->published_at = null;
            }
        });
    }

    /**
     * The only definition of "publicly visible". See the class docblock for
     * why the regulatory status is in the query and why a reviewer is not.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereNotNull('regulatory_status');
    }

    /** Peptides only — the gate that keeps amoxicillin out of the peptide wiki. */
    public function scopePeptides(Builder $query): Builder
    {
        return $query->where('is_peptide', true);
    }

    /**
     * Health goals this compound aligns with — the EDUCATION edge.
     *
     * Deliberately not derived from `ingredient->healthGoals()`: only 7 of the
     * 102 imported compounds map to a catalog ingredient, so deriving it would
     * leave 95 monographs showing no goals at all. "BPC-157 has shown efficacy
     * for recovery" is true whether or not this install stocks it.
     */
    public function healthGoals(): BelongsToMany
    {
        return $this->belongsToMany(HealthGoal::class, 'compound_health_goal')
            ->withPivot(['relevance_note', 'evidence_level', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewed_by_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * True when the monograph may be published — the form and the API both ask,
     * and `published()` is the same conditions as a query.
     */
    public function isPublishable(): bool
    {
        return $this->regulatory_status !== null;
    }

    /**
     * How many documents the source pipeline retrieved to write this.
     *
     * The honest provenance for this content: it is summarised from the
     * operator's clinical literature corpus by a retrieval pipeline, and this
     * is the size of the evidence base behind one monograph. Null when the
     * source did not record it — the public block is omitted rather than
     * claiming zero, because "0 sources" reads as a failure and "unrecorded"
     * is what actually happened.
     */
    public function sourceCount(): ?int
    {
        return $this->source_document_count ?: null;
    }
}
