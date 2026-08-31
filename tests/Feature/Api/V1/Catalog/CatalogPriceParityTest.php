<?php

namespace Tests\Feature\Api\V1\Catalog;

use App\Enums\BillingPeriod;
use App\Enums\CatalogStatus;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE CONTRACT THAT MAKES TWO IMPLEMENTATIONS OF ONE RULE SURVIVABLE.
 *
 * A card's figure is computed in PHP (`BuildsCatalogPricing::catalogPriceFrom`),
 * but `/stacks` must FILTER, SORT and take MIN/MAX over that same figure — and
 * a computed value cannot be any of those in SQL. So `Package::priceFromAmountSql()`
 * mirrors the AMOUNT half of the rule, and this file is the only thing standing
 * between that mirror and a silent divergence.
 *
 * Every test here asserts the SQL and the served `price_from.amount` agree on a
 * package built to exercise ONE branch. If you change the amount rule in the
 * trait and only one side moves, these fail — which is the entire point. Do not
 * "fix" a failure by editing the assertion.
 *
 * The branches, and why each is here rather than one happy-path package:
 * a monthly plan cheaper than the own price; an own price cheaper than every
 * plan; no own price at all (the plan fallback); no monthly plan (the
 * any-cadence fallback); a soft-deleted plan; an unpublished plan; an unpriced
 * plan; and an intro price, which must never be a candidate.
 */
class CatalogPriceParityTest extends TestCase
{
    use RefreshDatabase;

    /** The figure the SQL expression computes for one package. */
    private function sqlFigure(Package $package): ?float
    {
        $row = Package::query()
            ->whereKey($package->id)
            ->selectRaw(Package::priceFromAmountSql().' as figure')
            ->first();

        return $row?->figure !== null ? round((float) $row->figure, 2) : null;
    }

    /** The figure the API actually serves, which is what a card renders. */
    private function servedFigure(Package $package): ?float
    {
        $amount = $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->json('data.price_from.amount');

        return $amount !== null ? round((float) $amount, 2) : null;
    }

    private function assertParity(Package $package, ?float $expected): void
    {
        $served = $this->servedFigure($package);
        $sql = $this->sqlFigure($package);

        $this->assertSame($expected, $served, 'The SERVED figure is not what this branch should produce.');
        $this->assertSame(
            $served,
            $sql,
            "The SQL expression and the served figure disagree: card shows {$served}, "
            .'the /stacks filter and slider would use '.var_export($sql, true).'. '
            .'Package::priceFromAmountSql() has drifted from BuildsCatalogPricing::catalogPriceFrom().',
        );
    }

    private function package(array $attributes = []): Package
    {
        return Package::factory()->create($attributes + [
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
            'price_suffix' => null,
        ]);
    }

    private function plan(Package $package, array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes + [
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
        ]);
    }

    public function test_parity_when_a_monthly_plan_undercuts_the_own_price(): void
    {
        $package = $this->package(['retail_price' => 399.00]);
        $this->plan($package, ['retail_price' => 279.99]);

        $this->assertParity($package, 279.99);
    }

    public function test_parity_when_the_own_price_undercuts_every_plan(): void
    {
        $package = $this->package(['retail_price' => 399.00, 'sale_price' => 79.49]);
        $this->plan($package, ['retail_price' => 279.99]);

        $this->assertParity($package, 79.49);
    }

    public function test_parity_when_there_is_no_own_price(): void
    {
        $package = $this->package();
        $this->plan($package, ['retail_price' => 199.00]);

        $this->assertParity($package, 199.00);
    }

    public function test_parity_when_nothing_is_monthly(): void
    {
        // The any-cadence fallback: a package sold only as a prepay term.
        $package = $this->package();
        $this->plan($package, [
            'billing_period' => BillingPeriod::SemiAnnual,
            'term_months' => 6,
            'retail_price' => 899.00,
        ]);

        $this->assertParity($package, 899.00);
    }

    public function test_parity_ignores_a_soft_deleted_plan(): void
    {
        // THE ONE THE RAW SQL GETS WRONG BY DEFAULT. The Eloquent relation hides
        // trashed rows; `deleted_at IS NULL` is what makes SQL agree. There is a
        // trashed plan in this install's live data, so this is not theoretical.
        $package = $this->package(['retail_price' => 399.00]);
        $this->plan($package, ['retail_price' => 99.00])->delete();

        $this->assertParity($package, 399.00);
    }

    public function test_parity_ignores_an_unpublished_plan(): void
    {
        $package = $this->package(['retail_price' => 399.00]);
        $this->plan($package, ['retail_price' => 99.00, 'status' => CatalogStatus::Draft]);

        $this->assertParity($package, 399.00);
    }

    public function test_parity_ignores_an_unpriced_plan(): void
    {
        // A plan with neither price contributes nothing. Without the priced
        // guard, MIN() over it is NULL and the CASE takes the wrong branch.
        $package = $this->package(['retail_price' => 399.00]);
        $this->plan($package, ['retail_price' => null, 'sale_price' => null]);

        $this->assertParity($package, 399.00);
    }

    public function test_parity_ignores_an_intro_price(): void
    {
        $package = $this->package();
        $this->plan($package, ['retail_price' => 279.99, 'intro_price' => 99.00]);

        $this->assertParity($package, 279.99);
    }

    /**
     * THE DEFECT THIS EXPRESSION WAS BUILT FOR, stated as a test.
     *
     * The filter used to run `whereHas('plans', ...)` on plan prices alone, so
     * a package's own price was invisible to it. Live symptom: cards read
     * "As low as $399.00" and vanished at a $350 minimum, because their plans
     * were $279.99 and $671.98 with nothing in between. A package with no plans
     * at all could never match any price range.
     */
    public function test_the_price_filter_keeps_the_packages_whose_card_figure_is_in_range(): void
    {
        // Figure 399, from its own price: its only plan is a quarterly PREPAY
        // TOTAL, which never joins the pool. Every plan price it has sits
        // outside the 350-500 window, so the old `whereHas('plans')` filter
        // dropped it while its card read $399 — the exact live symptom.
        $ownPriced = $this->package(['retail_price' => 399.00]);
        $this->plan($ownPriced, ['billing_period' => BillingPeriod::Quarterly, 'term_months' => 3, 'retail_price' => 671.98]);

        // Figure 279.99, from the plan.
        $planPriced = $this->package(['retail_price' => 899.00]);
        $this->plan($planPriced, ['retail_price' => 279.99]);

        // No plans at all — unreachable by the old filter at any range.
        $plansless = $this->package(['retail_price' => 420.00]);

        $slugs = collect($this->getJson('/api/v1/catalog/packages?price_min=350&price_max=500')
            ->assertOk()
            ->json('data'))
            ->pluck('slug');

        $this->assertContains($ownPriced->slug, $slugs->all(), 'A package whose card reads $399 was dropped by a 350-500 filter.');
        $this->assertContains($plansless->slug, $slugs->all(), 'A package with no plans can never match a price filter.');
        $this->assertNotContains($planPriced->slug, $slugs->all(), 'A package whose card reads $279.99 was kept by a 350-500 filter.');
    }

    public function test_the_price_sort_orders_by_the_card_figure_not_the_own_columns(): void
    {
        // Own columns would sort these 100, 900 — the reverse of their cards,
        // and a package with NULL own columns sorts first in MySQL regardless
        // of what its plans cost.
        $dearer = $this->package(['retail_price' => 100.00]);
        $this->plan($dearer, ['retail_price' => 100.00]);

        $cheaper = $this->package(['retail_price' => 900.00]);
        $this->plan($cheaper, ['retail_price' => 50.00]);

        $slugs = collect($this->getJson('/api/v1/catalog/packages?sort=price')->assertOk()->json('data'))
            ->pluck('slug')
            ->values()
            ->all();

        $this->assertSame([$cheaper->slug, $dearer->slug], $slugs);
    }

    /**
     * The slider's ends must span the figures on the cards, or a visitor can
     * drag to a range that hides something the unfiltered page shows.
     */
    public function test_the_facet_bounds_span_the_card_figures_and_ignore_products(): void
    {
        $low = $this->package(['retail_price' => 900.00]);
        $this->plan($low, ['retail_price' => 120.00]);

        $high = $this->package(['retail_price' => 1450.00]);

        // A product far outside the package range: `price` must not move.
        Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 25.00,
            'sale_price' => null,
        ]);

        $facets = $this->getJson('/api/v1/catalog/facets')->assertOk();

        $facets->assertJsonPath('data.package_price.min', 120)
            ->assertJsonPath('data.package_price.max', 1450)
            ->assertJsonPath('data.price.min', 25)
            ->assertJsonPath('data.price.max', 25);
    }

    /**
     * PRODUCTS RUN THE SAME EXPRESSION, so they get the same contract.
     *
     * Nothing here changes a live figure — no product carries a monthly plan,
     * so a product's own price is its card figure either way. That is exactly
     * why the test matters: the agreement is currently a coincidence, and the
     * day a product gets a cheaper monthly plan these are the assertions that
     * decide whether its filter, sort and slider follow its cards or silently
     * stop matching them.
     */
    public function test_parity_for_a_product_whose_monthly_plan_undercuts_its_own_price(): void
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

        $served = $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->json('data.price_from.amount');

        $sql = Product::query()
            ->whereKey($product->id)
            ->selectRaw(Product::priceFromAmountSql().' as figure')
            ->first()?->figure;

        $this->assertEquals(199, $served);
        $this->assertEquals($served, $sql, 'Product::priceFromAmountSql() disagrees with the served product figure.');
    }

    /**
     * A PRODUCT'S PREPAY TERM PLAN MUST NOT BECOME ITS FILTER FIGURE EITHER.
     *
     * The mixed-unit guard has to hold on both sides of the parity, or the
     * filter would keep a product the card never advertised at that price.
     */
    public function test_parity_for_a_product_with_only_a_prepay_term_plan(): void
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

        $served = $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->json('data.price_from.amount');

        $sql = Product::query()
            ->whereKey($product->id)
            ->selectRaw(Product::priceFromAmountSql().' as figure')
            ->first()?->figure;

        $this->assertEquals(399, $served);
        $this->assertEquals($served, $sql);
    }

    public function test_the_product_price_filter_uses_the_card_figure(): void
    {
        $cheapByPlan = Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 900.00,
            'sale_price' => null,
        ]);
        Plan::factory()->for($cheapByPlan)->create([
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 120.00,
        ]);

        $dear = Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 900.00,
            'sale_price' => null,
        ]);

        $slugs = collect($this->getJson('/api/v1/catalog/products?price_min=100&price_max=200')
            ->assertOk()
            ->json('data'))
            ->pluck('slug')
            ->all();

        $this->assertContains($cheapByPlan->slug, $slugs, 'A product whose card reads $120 was dropped by a 100-200 filter.');
        $this->assertNotContains($dear->slug, $slugs);
    }

    public function test_parity_when_nothing_is_priced_at_all(): void
    {
        $package = $this->package();
        $this->plan($package, ['retail_price' => null, 'sale_price' => null]);

        $this->assertParity($package, null);
    }
}
