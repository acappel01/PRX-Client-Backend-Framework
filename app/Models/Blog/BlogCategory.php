<?php

namespace App\Models\Blog;

use App\Models\Concerns\HasSlugHistory;
use App\Models\Concerns\GeneratesUniqueSlug;
use Database\Factories\Blog\BlogCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class BlogCategory extends Model implements Sortable
{
    use GeneratesUniqueSlug, HasFactory, HasSlugHistory, SoftDeletes, SortableTrait;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'hero_image_path',
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

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(
            BlogPost::class,
            'blog_category_post',
            'blog_category_id',
            'blog_post_id'
        );
    }

    protected static function newFactory(): BlogCategoryFactory
    {
        return BlogCategoryFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
