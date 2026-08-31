<?php

namespace Tests\Feature\Api\V1\Catalog;

use App\Enums\BillingPeriod;
use App\Enums\CatalogRelationType;
use App\Enums\CatalogStatus;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Kb\HealthGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health-goal badges, and the derivation rule for packages.
 *
 * The rule is easy to state and easy to break: a package shows the union of
 * its products' goals, UNLESS it has its own, in which case its own replace
 * them entirely. "Replace" rather than "add to" is the half that keeps
 * getting re-implemented as a merge, so it is pinned here.
 */
class HealthGoalBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function goal(string $name, ?string $badgeColor = null): HealthGoal
    {
        return HealthGoal::factory()->create([
            'name' => $name,
            'badge_color' => $badgeColor,
        ]);
    }

    public function test_a_product_serves_its_health_goal_badges(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $product->healthGoals()->attach($this->goal('Weight Management', 'moss')->id);

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.health_goals.0.name', 'Weight Management')
            ->assertJsonPath('data.health_goals.0.badge_color', 'moss');
    }

    public function test_a_goal_without_a_badge_colour_still_serves_a_badge(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $product->healthGoals()->attach($this->goal('Sleep & Recovery')->id);

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.health_goals.0.badge_color', null);
    }

    public function test_products_index_carries_badges(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $product->healthGoals()->attach($this->goal('Energy & Focus', 'sand')->id);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonPath('data.0.health_goals.0.badge_color', 'sand');
    }

    public function test_a_package_derives_badges_from_the_products_inside_it(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);

        $one = Product::factory()->create(['status' => CatalogStatus::Published]);
        $one->healthGoals()->attach($this->goal('Weight Management', 'moss')->id);

        $two = Product::factory()->create(['status' => CatalogStatus::Published]);
        $two->healthGoals()->attach($this->goal('Energy & Focus', 'sand')->id);

        $package->products()->attach([$one->id, $two->id]);

        $names = $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->json('data.health_goals.*.name');

        sort($names);
        $this->assertSame(['Energy & Focus', 'Weight Management'], $names);
    }

    public function test_a_goal_shared_by_two_products_is_not_shown_twice(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $goal = $this->goal('Weight Management', 'moss');

        foreach (range(1, 2) as $ignored) {
            $product = Product::factory()->create(['status' => CatalogStatus::Published]);
            $product->healthGoals()->attach($goal->id);
            $package->products()->attach($product->id);
        }

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.health_goals');
    }

    /**
     * THE REVIEW GATE CAUGHT THIS ONE. Deriving badges needs the contained
     * products, and the obvious eager load — `products.healthGoals` on the
     * index — switched on PackageResource's `whenLoaded('products')` as a side
     * effect. The listing silently began serving a full nested ProductResource
     * per package: names, prices, SKU fields it had never exposed. Nothing
     * looked wrong, because every product in the catalog happened to be
     * published.
     */
    public function test_the_package_listing_does_not_embed_its_products(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $product->healthGoals()->attach($this->goal('Weight Management', 'moss')->id);
        $package->products()->attach($product->id);

        $response = $this->getJson('/api/v1/catalog/packages')->assertOk();

        // The badge still derives...
        $response->assertJsonPath('data.0.health_goals.0.name', 'Weight Management');
        // ...but the products themselves stay off the listing.
        $this->assertArrayNotHasKey('products', $response->json('data.0'));
    }

    /**
     * The second half of the same defect: Package::products() has no status
     * constraint, so an unpublished product inside a published stack would
     * have badged a card whose own detail page shows no such badge — and, via
     * the leak above, had its name and price served outright.
     */
    public function test_an_unpublished_product_does_not_badge_its_package(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);

        $live = Product::factory()->create(['status' => CatalogStatus::Published]);
        $live->healthGoals()->attach($this->goal('Weight Management', 'moss')->id);

        $draft = Product::factory()->create(['status' => CatalogStatus::Draft]);
        $draft->healthGoals()->attach($this->goal('Secret Goal', 'sand')->id);

        $package->products()->attach([$live->id, $draft->id]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.health_goals')
            ->assertJsonPath('data.health_goals.0.name', 'Weight Management');
    }

    public function test_a_packages_own_badges_replace_the_derived_ones(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);

        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $product->healthGoals()->attach($this->goal('Energy & Focus', 'sand')->id);
        $package->products()->attach($product->id);

        $package->healthGoals()->attach($this->goal('Weight Management', 'moss')->id);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.health_goals')
            ->assertJsonPath('data.health_goals.0.name', 'Weight Management');
    }

    /**
     * The "What's Included" rows render from `data.products[*]`, not from the
     * package's own badges — so this asserts the SIBLING load, which is a
     * different relation from the one derivation uses.
     *
     * This is the compensating control for `health_goals` being a stable key
     * that defaults to []. A forgotten eager load does not fail anything; it
     * just serves an empty array that looks exactly like an untagged product.
     * The full suite passed while this was broken.
     */
    public function test_the_products_inside_a_package_carry_their_own_badges(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $product->healthGoals()->attach($this->goal('Weight Management', 'moss')->id);
        $package->products()->attach($product->id);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.products.0.health_goals.0.name', 'Weight Management')
            ->assertJsonPath('data.products.0.health_goals.0.badge_color', 'moss');
    }

    public function test_relation_rails_carry_badges_for_both_kinds(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $product->healthGoals()->attach($this->goal('Weight Management', 'moss')->id);

        $related = Product::factory()->create(['status' => CatalogStatus::Published]);
        $related->healthGoals()->attach($this->goal('Sleep & Recovery', 'shell')->id);

        $product->catalogRelations()->create([
            'relation_type' => CatalogRelationType::PairsWith->value,
            'related_type' => Product::class,
            'related_id' => $related->id,
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.pairs_with.0.health_goals.0.badge_color', 'shell');
    }

    /**
     * A RAIL MIXES BOTH KINDS IN ONE ROW, SO BOTH MUST CARRY THE CARD FIGURE.
     *
     * This is the test that was missing when `CatalogRelationItemResource`
     * still gated `price_from` on `instanceof Package`. Everything stayed green
     * while every product on a related rail, the Pairs With slider and the
     * cart upsells shipped `price_from: null` — and the eager load added for
     * them ran its query and threw the answer away. On the wire that row read
     * "As low as $349.00/mo" next to a bare "$249.00" for the same kind of
     * thing.
     *
     * ASSERTING THE KEY IS POPULATED, NOT MERELY PRESENT, IS THE POINT — third
     * time this bug class has landed on this project. `null` does not fail a
     * request; the card silently falls back to the item's own price and
     * disagrees with the item's own page.
     *
     * Deleting the `plans` closure from HasCatalogRelations::resolveRelationTargets,
     * or restoring the kind gate, must fail here.
     */
    public function test_relation_rails_carry_the_card_figure_for_both_kinds(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);

        $relatedProduct = Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 249.00,
            'sale_price' => null,
        ]);
        Plan::factory()->for($relatedProduct)->create([
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 199.00,
        ]);

        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 349.00,
        ]);

        foreach ([[Product::class, $relatedProduct->id], [Package::class, $package->id]] as [$type, $id]) {
            $product->catalogRelations()->create([
                'relation_type' => CatalogRelationType::PairsWith->value,
                'related_type' => $type,
                'related_id' => $id,
            ]);
        }

        $response = $this->getJson("/api/v1/catalog/products/{$product->slug}")->assertOk();

        $figures = collect($response->json('data.pairs_with'))
            ->mapWithKeys(fn ($row) => [$row['type'] => $row['price_from']['amount'] ?? null]);

        $this->assertNotNull(
            $figures['product'] ?? null,
            'A product on a rail carries no price_from — either the plans eager load was dropped '
            .'or the resource gated the figure on kind. The rail will mix "As low as $X" cards '
            .'with bare own-price ones.',
        );
        // assertEquals, not assertSame: whole floats lose their zero fraction
        // over JSON and decode as ints — the same note as the test below.
        $this->assertEquals(199, $figures['product']);
        $this->assertEquals(349, $figures['package']);
    }

    /**
     * The item-26 defect: a rail substituted a package with its default plan,
     * so a buy-once stack advertised the subscription's price.
     */
    public function test_a_related_package_is_priced_by_itself_not_by_its_plan(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);

        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'is_default' => true,
            'retail_price' => 279.99,
        ]);

        $product->catalogRelations()->create([
            'relation_type' => CatalogRelationType::PairsWith->value,
            'related_type' => Package::class,
            'related_id' => $package->id,
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            // Whole floats lose their zero fraction over JSON and decode as
            // ints; 279.99 keeps its decimals. Not a rounding bug.
            ->assertJsonPath('data.pairs_with.0.price.effective', 399)
            ->assertJsonPath('data.pairs_with.0.price_range.from', 279.99)
            ->assertJsonPath('data.pairs_with.0.price_range.to', 399);
    }

    /**
     * Most packages have no price of their own and are sold through plans.
     * Dropping the plan substitution must not leave those cards blank — the
     * range is what lets one honestly say "From $X".
     */
    public function test_a_priceless_related_package_still_carries_a_range(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);

        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'retail_price' => 149.00,
        ]);

        $product->catalogRelations()->create([
            'relation_type' => CatalogRelationType::PairsWith->value,
            'related_type' => Package::class,
            'related_id' => $package->id,
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.pairs_with.0.price.effective', null)
            ->assertJsonPath('data.pairs_with.0.price_range.from', 149);
    }
}
