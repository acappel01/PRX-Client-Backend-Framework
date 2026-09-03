<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CatalogStatus;
use App\Models\Blog\BlogPost;
use App\Models\Catalog\Category;
use App\Models\Kb\HealthGoal;
use App\Models\Catalog\Product;
use App\Models\SlugHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Renaming a slug renames a public URL. These cover the two halves that make
 * the redirect safe: history is written on rename, and a LIVE record always
 * beats history so a redirect can never hijack a URL somebody is using.
 */
class SlugRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug): Product
    {
        return Product::factory()->create(['slug' => $slug]);
    }

    public function test_renaming_a_product_records_its_previous_slug(): void
    {
        $product = $this->product('old-name');

        $product->slug = 'new-name';
        $product->save();

        $this->assertDatabaseHas('slug_histories', [
            'subject_type' => $product->getMorphClass(),
            'subject_id' => $product->id,
            'slug' => 'old-name',
        ]);
    }

    public function test_a_previous_slug_resolves_to_the_current_one(): void
    {
        $product = $this->product('old-name');
        $product->slug = 'new-name';
        $product->save();

        $this->getJson('/api/v1/slug-redirect?type=product&slug=old-name')
            ->assertOk()
            ->assertJsonPath('data.type', 'product')
            ->assertJsonPath('data.slug', 'new-name');
    }

    /**
     * The property that makes a chain impossible: history points at the RECORD,
     * so the oldest slug lands on the newest name in one hop rather than
     * walking a -> b -> c.
     */
    public function test_the_oldest_slug_resolves_to_the_newest_in_one_hop(): void
    {
        $product = $this->product('name-a');

        $product->slug = 'name-b';
        $product->save();

        $product->slug = 'name-c';
        $product->save();

        $this->getJson('/api/v1/slug-redirect?type=product&slug=name-a')
            ->assertOk()
            ->assertJsonPath('data.slug', 'name-c');

        $this->getJson('/api/v1/slug-redirect?type=product&slug=name-b')
            ->assertOk()
            ->assertJsonPath('data.slug', 'name-c');
    }

    /**
     * THE SAFETY PROPERTY. If a live record holds the slug, no redirect is
     * offered — otherwise a stale history row could bounce visitors away from a
     * page that legitimately exists.
     */
    public function test_a_live_slug_is_never_redirected(): void
    {
        $product = $this->product('old-name');
        $product->slug = 'new-name';
        $product->save();

        // A different product now claims the abandoned name.
        $this->product('old-name');

        $this->getJson('/api/v1/slug-redirect?type=product&slug=old-name')
            ->assertNotFound();
    }

    public function test_reclaiming_a_slug_removes_its_history_row(): void
    {
        $product = $this->product('original');

        $product->slug = 'temporary';
        $product->save();

        $product->slug = 'original';
        $product->save();

        $this->assertDatabaseMissing('slug_histories', [
            'subject_type' => $product->getMorphClass(),
            'slug' => 'original',
        ]);

        $this->getJson('/api/v1/slug-redirect?type=product&slug=temporary')
            ->assertOk()
            ->assertJsonPath('data.slug', 'original');
    }

    /**
     * A trashed record must stop redirecting — otherwise the old URL sends
     * visitors to a page that itself 404s. But the rows stay, so restoring the
     * record restores its redirects.
     */
    public function test_a_soft_deleted_record_stops_redirecting_but_keeps_its_history(): void
    {
        $product = $this->product('old-name');
        $product->slug = 'new-name';
        $product->save();

        $product->delete();

        $this->getJson('/api/v1/slug-redirect?type=product&slug=old-name')
            ->assertNotFound();

        $this->assertDatabaseHas('slug_histories', [
            'subject_type' => $product->getMorphClass(),
            'slug' => 'old-name',
        ]);

        $product->restore();

        $this->getJson('/api/v1/slug-redirect?type=product&slug=old-name')
            ->assertOk()
            ->assertJsonPath('data.slug', 'new-name');
    }

    public function test_a_hard_delete_drops_the_history(): void
    {
        $product = $this->product('old-name');
        $product->slug = 'new-name';
        $product->save();

        $product->forceDelete();

        $this->assertDatabaseMissing('slug_histories', [
            'subject_type' => $product->getMorphClass(),
            'slug' => 'old-name',
        ]);
    }

    public function test_a_slug_that_was_never_used_is_not_found(): void
    {
        $this->getJson('/api/v1/slug-redirect?type=product&slug=never-existed')
            ->assertNotFound();
    }

    /**
     * Every registered type must actually work, not just `product`. A type in
     * the map whose model lacks the trait would validate, resolve nothing, and
     * look like "no redirect configured" rather than a wiring mistake.
     */
    #[DataProvider('redirectableTypes')]
    public function test_every_registered_type_records_and_resolves(string $type, string $model): void
    {
        $record = $model::factory()->create(['slug' => 'before']);

        $record->slug = 'after';
        $record->save();

        $this->getJson("/api/v1/slug-redirect?type={$type}&slug=before")
            ->assertOk()
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.slug', 'after');
    }

    public static function redirectableTypes(): array
    {
        return [
            'product' => ['product', Product::class],
            'catalog_category' => ['catalog_category', Category::class],
            'blog_post' => ['blog_post', BlogPost::class],
            'health_goal' => ['health_goal', HealthGoal::class],
        ];
    }

    /**
     * Drafting and renaming in one save must NOT redirect. Without this the old
     * URL 308s to a URL that itself 404s — two dead ends — and the Location
     * header discloses the current name of an unreleased record.
     */
    public function test_an_unpublished_target_is_not_redirected_to(): void
    {
        $product = $this->product('was-live');

        $product->slug = 'now-drafted';
        $product->status = CatalogStatus::Draft;
        $product->save();

        $this->getJson('/api/v1/slug-redirect?type=product&slug=was-live')
            ->assertNotFound();
    }

    public function test_a_target_that_is_published_again_resolves_once_more(): void
    {
        $product = $this->product('was-live');
        $product->slug = 'now-drafted';
        $product->status = CatalogStatus::Draft;
        $product->save();

        $product->status = CatalogStatus::Published;
        $product->save();

        $this->getJson('/api/v1/slug-redirect?type=product&slug=was-live')
            ->assertOk()
            ->assertJsonPath('data.slug', 'now-drafted');
    }

    /**
     * A NEW record claiming an abandoned name must clear the stale history row,
     * not merely shadow it. Shadowing looks fine until the claimant is deleted,
     * at which point a deleted record's URL starts redirecting to a DIFFERENT
     * record.
     */
    public function test_a_new_record_reclaiming_a_name_clears_the_stale_row(): void
    {
        $first = $this->product('shared-name');
        $first->slug = 'first-renamed';
        $first->save();

        $second = $this->product('shared-name');

        $this->assertDatabaseMissing('slug_histories', [
            'subject_type' => $first->getMorphClass(),
            'slug' => 'shared-name',
        ]);

        // The claimant going away must not resurrect the old redirect.
        $second->delete();

        $this->getJson('/api/v1/slug-redirect?type=product&slug=shared-name')
            ->assertNotFound();
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $this->getJson('/api/v1/slug-redirect?type=lead&slug=anything')
            ->assertStatus(422);
    }

    public function test_saving_without_changing_the_slug_records_nothing(): void
    {
        $product = $this->product('unchanged');

        $product->name = 'A different name entirely';
        $product->save();

        $this->assertSame(0, SlugHistory::query()->count());
    }
}
