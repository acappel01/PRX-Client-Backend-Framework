<?php

namespace App\Models\Blog;

use App\Enums\PostStatus;
use App\Models\Concerns\HasSlugHistory;
use App\Models\Concerns\GeneratesUniqueSlug;
use App\Models\Concerns\HasTags;
use App\Models\User;
use Database\Factories\Blog\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class BlogPost extends Model implements Sortable
{
    use GeneratesUniqueSlug, HasFactory, HasSlugHistory, HasTags, SoftDeletes, SortableTrait;

    protected $fillable = [
        'author_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'hero_image_path',
        'gallery',
        'status',
        'published_at',
        'featured',
        'read_time_minutes',
        'meta_title',
        'meta_description',
        'og_image_path',
        'position',
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
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'featured' => 'boolean',
            'gallery' => 'array',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            BlogCategory::class,
            'blog_category_post',
            'blog_post_id',
            'blog_category_id'
        );
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Published->value)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function isPublished(): bool
    {
        return $this->status === PostStatus::Published
            && ($this->published_at === null || $this->published_at->lte(now()));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function newFactory(): BlogPostFactory
    {
        return BlogPostFactory::new();
    }
}
