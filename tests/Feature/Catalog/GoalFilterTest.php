<?php

namespace Tests\Feature\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Catalog\Package;
use App\Models\Catalog\Product;
use App\Models\Kb\HealthGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health goals are the catalog's populated classification, so they drive the
 * listing filter. Same vocabulary the quiz matches on — a filtered listing and
 * a quiz result must not disagree about what a product is for.
 */
class GoalFilterTest extends TestCase
{
    use RefreshDatabase;

    private function productForGoal(HealthGoal $goal, CatalogStatus $status = CatalogStatus::Published): Product
    {
        $product = Product::factory()->create(['status' => $status]);
        $product->healthGoals()->attach($goal);

        return $product;
    }

    public function test_products_can_be_filtered_by_goal(): void
    {
        $wanted = HealthGoal::factory()->create(['slug' => 'weight-management', 'is_active' => true]);
        $other = HealthGoal::factory()->create(['slug' => 'sleep-recovery', 'is_active' => true]);

        $match = $this->productForGoal($wanted);
        $this->productForGoal($other);

        $this->getJson('/api/v1/catalog/products?goal=weight-management')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $match->slug);
    }

    public function test_an_unknown_goal_returns_nothing_rather_than_everything(): void
    {
        $goal = HealthGoal::factory()->create(['slug' => 'weight-management', 'is_active' => true]);
        $this->productForGoal($goal);

        $this->getJson('/api/v1/catalog/products?goal=no-such-goal')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A facet offering a goal with no published products behind it is a filter
     * that leads to an empty page, so zero-count goals are omitted.
     */
    public function test_the_facet_counts_published_products_only(): void
    {
        $goal = HealthGoal::factory()->create(['slug' => 'weight-management', 'is_active' => true]);
        $this->productForGoal($goal, CatalogStatus::Draft);

        $this->getJson('/api/v1/catalog/facets')
            ->assertOk()
            ->assertJsonMissing(['slug' => 'weight-management']);

        $this->productForGoal($goal);

        $this->getJson('/api/v1/catalog/facets')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'weight-management', 'count' => 1]);
    }

    /**
     * One endpoint serves both listings, so a facet row must say how many
     * PACKAGES it has as well as how many products. Without it the stacks
     * listing offers goals counted by products — options that return nothing.
     */
    public function test_a_facet_row_carries_both_product_and_package_counts(): void
    {
        $goal = HealthGoal::factory()->create(['slug' => 'weight-management', 'is_active' => true]);
        $this->productForGoal($goal);

        $this->getJson('/api/v1/catalog/facets')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'weight-management', 'count' => 1, 'package_count' => 0]);

        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $package->healthGoals()->attach($goal);

        $this->getJson('/api/v1/catalog/facets')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'weight-management', 'count' => 1, 'package_count' => 1]);
    }

    /**
     * A facet with packages but no products must still be offered — dropping on
     * the product count alone is what hid package-only classifications from the
     * stacks filter entirely.
     */
    public function test_a_package_only_goal_is_still_offered(): void
    {
        $goal = HealthGoal::factory()->create(['slug' => 'sleep-recovery', 'is_active' => true]);
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $package->healthGoals()->attach($goal);

        $this->getJson('/api/v1/catalog/facets')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'sleep-recovery', 'count' => 0, 'package_count' => 1]);
    }

    /**
     * THE PACKAGE CASE, and the one the first cut got wrong. A package's
     * `healthGoals` is a badge OVERRIDE, empty in the normal case; the badges a
     * card shows are the union of its published products' goals. Filtering on
     * the override alone means every stack displays goals and none is
     * filterable by them.
     */
    public function test_a_package_is_filterable_by_the_goals_its_contents_carry(): void
    {
        $goal = HealthGoal::factory()->create(['slug' => 'metabolic-health', 'is_active' => true]);
        $product = $this->productForGoal($goal);

        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $package->products()->attach($product);

        // No override row anywhere — the normal case.
        $this->assertCount(0, $package->healthGoals);

        $this->getJson('/api/v1/catalog/packages?goal=metabolic-health')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $package->slug);

        $this->getJson('/api/v1/catalog/facets')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'metabolic-health', 'package_count' => 1]);
    }

    /**
     * An override REPLACES the derived set, exactly as the badge builder treats
     * it — so a package pinned to one goal stops matching its contents' others.
     */
    public function test_an_override_replaces_the_derived_goals(): void
    {
        $derived = HealthGoal::factory()->create(['slug' => 'metabolic-health', 'is_active' => true]);
        $pinned = HealthGoal::factory()->create(['slug' => 'sleep-recovery', 'is_active' => true]);

        $product = $this->productForGoal($derived);
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $package->products()->attach($product);
        $package->healthGoals()->attach($pinned);

        $this->getJson('/api/v1/catalog/packages?goal=sleep-recovery')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/catalog/packages?goal=metabolic-health')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_inactive_goal_is_not_offered_as_a_facet(): void
    {
        $goal = HealthGoal::factory()->create(['slug' => 'retired-goal', 'is_active' => false]);
        $this->productForGoal($goal);

        $this->getJson('/api/v1/catalog/facets')
            ->assertOk()
            ->assertJsonMissing(['slug' => 'retired-goal']);
    }
}
