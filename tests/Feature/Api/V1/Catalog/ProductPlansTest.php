<?php

namespace Tests\Feature\Api\V1\Catalog;

use App\Enums\BillingPeriod;
use App\Enums\CatalogStatus;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_show_exposes_published_term_plans_in_position_order(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);

        // Sortable's sort_when_creating ignores explicit position values —
        // create in display order (see feedback-sortable-trait-position).
        Plan::factory()->for($product)->default()->create([
            'name' => 'Monthly Plan',
            'retail_price' => 99.00,
        ]);
        Plan::factory()->for($product)->quarterly()->create([
            'name' => '3-Month Plan',
            'retail_price' => 279.00,
        ]);
        Plan::factory()->for($product)->draft()->create([
            'name' => 'Hidden Plan',
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->assertJsonCount(2, 'data.plans')
            ->assertJsonPath('data.plans.0.name', 'Monthly Plan')
            ->assertJsonPath('data.plans.0.is_default', true)
            ->assertJsonPath('data.plans.0.price.effective', 99)
            ->assertJsonPath('data.plans.1.name', '3-Month Plan')
            ->assertJsonPath('data.plans.1.billing.term_months', 3);
    }

    public function test_product_index_does_not_expose_plans(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        Plan::factory()->for($product)->create();

        $response = $this->getJson('/api/v1/catalog/products')->assertOk();

        $this->assertArrayNotHasKey('plans', $response->json('data.0'));
    }

    /**
     * A PRODUCT CARRIES THE CARD FIGURE TOO, AND THE LISTING STILL MUST NOT
     * SHIP THE PLANS THEMSELVES.
     *
     * Those two facts pull in opposite directions and that is the whole reason
     * this test exists. `price_from` can only be computed when `plans` is
     * loaded, so the listing eager-loads the relation — but the listing payload
     * is contractually plan-free (the test above), and a resource keyed on
     * `whenLoaded` alone would have started shipping every plan the moment that
     * load was added for the figure. The key is route-gated for exactly this.
     *
     * Asserting the figure is POPULATED rather than merely present is the other
     * half: `null` is what a missing eager load produces, and it does not fail a
     * request — the card silently falls back to the product's own price and
     * disagrees with the detail page about the same product.
     */
    public function test_product_index_carries_the_card_figure_without_the_plans(): void
    {
        $product = Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 249.00,
            'sale_price' => null,
        ]);
        Plan::factory()->for($product)->create([
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 199.00,
        ]);

        $response = $this->getJson('/api/v1/catalog/products')->assertOk();

        $this->assertArrayNotHasKey('plans', $response->json('data.0'));
        $this->assertNotNull(
            $response->json('data.0.price_from.amount'),
            'price_from is null — the plans relation was not eager loaded on the listing, '
            .'so these cards will disagree with the product page about the same product.',
        );
        $response->assertJsonPath('data.0.price_from.amount', 199);
    }

    /**
     * A PRODUCT'S TERM PLANS ARE PREPAY TOTALS, AND THE CADENCE FILTER IS WHAT
     * KEEPS THEM OFF A CARD.
     *
     * This is the reason products were originally given no card figure at all.
     * A 3-month plan at $279 is a TOTAL, not a rate, so a rule that pooled it
     * against a $99 monthly price by raw amount would be comparing different
     * units — and the moment a product's own price sat above a prepay total, a
     * card would advertise "as low as $279.00" for something billed quarterly.
     * Only monthly-cadence plans join the pool, so the own price wins here.
     */
    public function test_a_products_prepay_term_plan_never_becomes_its_card_figure(): void
    {
        $product = Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
            'sale_price' => null,
        ]);
        Plan::factory()->for($product)->quarterly()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 279.00,
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 399)
            ->assertJsonPath('data.price_from.plan_id', null);
    }

    public function test_plan_cannot_belong_to_both_package_and_product(): void
    {
        $package = Package::factory()->create();
        $product = Product::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        Plan::factory()->for($package)->for($product)->create();
    }

    public function test_product_plans_do_not_leak_into_package_plan_lists(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);

        Plan::factory()->for($package)->create(['name' => 'Package Plan']);
        Plan::factory()->for($product)->create(['name' => 'Product Plan']);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.plans')
            ->assertJsonPath('data.plans.0.name', 'Package Plan');
    }
}
