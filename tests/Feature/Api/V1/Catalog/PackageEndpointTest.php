<?php

namespace Tests\Feature\Api\V1\Catalog;

use App\Enums\BillingPeriod;
use App\Enums\CatalogStatus;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_packages_index_returns_published_packages_only(): void
    {
        Package::factory()->create(['status' => CatalogStatus::Published, 'name' => 'Visible']);
        Package::factory()->create(['status' => CatalogStatus::Draft, 'name' => 'Hidden']);

        $this->getJson('/api/v1/catalog/packages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Visible');
    }

    public function test_packages_index_includes_published_plans(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Draft]);

        $this->getJson('/api/v1/catalog/packages')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.plans');
    }

    public function test_packages_index_response_shape(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 199.00]);

        $this->getJson('/api/v1/catalog/packages')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'name', 'slug', 'badge_text', 'highlights',
                    'is_featured', 'requires_lab', 'price_range', 'price_from', 'provider',
                    'plans', 'categories', 'tags',
                ]],
            ]);
    }

    public function test_package_show_returns_single_published_package(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $package->slug);
    }

    public function test_package_show_includes_products(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        $product = Product::factory()->create(['status' => CatalogStatus::Published]);
        $package->products()->attach($product->id, ['sort_order' => 1, 'is_included' => true]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', $product->name);
    }

    public function test_package_show_computes_price_range_from_plans(): void
    {
        // The package's own price is explicit and sits INSIDE the plan span, so
        // the range is the plans'. It used to be whatever the factory rolled,
        // which passed only because the resource ignored it entirely.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 199.00,
            'sale_price' => null,
        ]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'sale_price' => 99.00]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'sale_price' => 249.00]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_range.from', 99)
            ->assertJsonPath('data.price_range.to', 249)
            ->assertJsonPath('data.price_range.currency', 'USD');
    }

    public function test_a_package_on_sale_below_its_cheapest_plan_moves_the_range(): void
    {
        // THE CASE THE OPERATOR ASKED ABOUT. Plans are normally the cheaper way
        // to buy, so a range read from plans alone looks right almost always —
        // and is wrong exactly when a single purchase is discounted beneath
        // them, which is what putting a package on sale is for. A range that
        // ignored it would advertise a "from" higher than the cheapest thing on
        // the page.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
            'sale_price' => 79.00,
        ]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'sale_price' => 99.00]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'sale_price' => 249.00]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_range.from', 79)
            ->assertJsonPath('data.price_range.to', 249)
            ->assertJsonPath('data.is_on_sale', true);
    }

    public function test_a_package_with_no_plans_still_reports_its_own_price(): void
    {
        // A package can be a single product. Its price is then the whole range,
        // and the detail page has something to show — where before both the
        // price block and the range were absent.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
            'sale_price' => null,
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price.effective', 399)
            ->assertJsonPath('data.price_range.from', 399)
            ->assertJsonPath('data.price_range.to', 399);
    }

    public function test_the_package_price_block_is_served_at_all(): void
    {
        // The regression that started this: an operator set the package to $399,
        // the save worked, and the storefront kept showing the default plan's
        // price, because nothing ever emitted the package's own.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
            'sale_price' => 349.00,
            'price_suffix' => '/mo',
        ]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 279.99]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price.retail', 399)
            ->assertJsonPath('data.price.sale', 349)
            ->assertJsonPath('data.price.effective', 349)
            ->assertJsonPath('data.price.suffix', '/mo');
    }

    // ── price_from: the single figure a listing card leads with ──────────

    public function test_price_from_is_the_packages_own_price_even_when_a_plan_is_cheaper(): void
    {
        // THE RULE THIS FIELD EXISTS FOR, and it is commercial rather than
        // arithmetic. The package is buyable outright at $399. The $279.99 is
        // the entry price of a monthly SUBSCRIPTION laid over the same bundle —
        // a different transaction, not a cheaper label for this one. A card's
        // button buys the bundle, so leading with the plan understates what is
        // about to be bought and commits the visitor to a rebill they never
        // chose. Plans are presented on the stack's own page, which is where a
        // recurring commitment belongs.
        //
        // The range is asserted alongside on purpose: it is still correct for
        // what it measures, and a card still cannot show it, because its two
        // ends are in different units — $279.99 a month against a $1,259.96
        // six-month TOTAL, which on a card reads as a stack costing $1,259.96
        // a month.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
            'sale_price' => null,
            'price_suffix' => null,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 279.99,
            'price_suffix' => '/mo',
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::SemiAnnual,
            'term_months' => 6,
            'retail_price' => 1259.96,
            'price_suffix' => '/6mo',
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 399)
            ->assertJsonPath('data.price_from.suffix', null)
            ->assertJsonPath('data.price_from.currency', 'USD')
            // NULL CARRIES TWO MEANINGS AND BOTH MATTER. It tells the cart to
            // add the package itself with no plan and no rebill, and it tells a
            // card the figure is exact — render it bare, not as "From $399".
            ->assertJsonPath('data.price_from.plan_id', null)
            ->assertJsonPath('data.price_range.from', 279.99)
            ->assertJsonPath('data.price_range.to', 1259.96);
    }

    public function test_price_from_ignores_a_cheaper_price_charged_in_another_unit(): void
    {
        // THE TEST THAT PINS THE FALLBACK RULE, and it needs a package with no
        // own price to reach it at all — with one set, the own price pre-empts
        // every plan and this file's headline test applies instead.
        //
        // A mutation proved the first version of this was theatre: the monthly
        // plan was also the numerically cheapest, so deleting the cadence
        // filter outright still produced 279.99 and every assertion passed.
        // Here a quarterly plan is the smallest NUMBER ($199 for three months)
        // while the monthly rate is $279.99, so "cheapest monthly" and
        // "cheapest number" give different answers and only the correct rule
        // returns 279.99. Comparing raw amounts across billing units is
        // meaningless, which is the whole reason this field exists.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        $monthly = Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 279.99,
            'price_suffix' => '/mo',
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Quarterly,
            'term_months' => 3,
            'retail_price' => 199.00,
            'price_suffix' => '/qtr',
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 279.99)
            ->assertJsonPath('data.price_from.suffix', '/mo')
            // And the id follows the same rule as the figure: the MONTHLY plan,
            // not the numerically cheaper quarterly one. Asserting the amount
            // alone cannot tell those apart once a cart adds by id. Non-null
            // also means a card renders "From $279.99/mo" — this IS a floor.
            ->assertJsonPath('data.price_from.plan_id', $monthly->id)
            // The range still reports the raw span, unit-blind and correct for
            // what it measures — the two fields must not collapse into one.
            ->assertJsonPath('data.price_range.from', 199);
    }

    public function test_price_from_ignores_the_intro_price(): void
    {
        // intro_price buys ONE billing cycle. Leading a card with it advertises
        // a number the visitor pays once and then stops paying; the plan picker
        // on the detail page is where that offer belongs.
        //
        // No own price on the package, deliberately — this rule lives in the
        // plan fallback, and a package with an own price never reaches it.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 279.99,
            'intro_price' => 179.99,
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 279.99);
    }

    public function test_price_from_prefers_the_packages_sale_price_and_passes_its_suffix_through(): void
    {
        // A package on sale is buyable at the sale price, so that is the figure
        // — the same sale ?? retail rule the cart prices the line by, and the
        // two must not disagree.
        //
        // THE SUFFIX IS THE PACKAGE'S OWN STRING, VERBATIM AND UNVALIDATED.
        // A package's own price has no cadence column, only free text an
        // operator typed, so nothing here can tell "/ea" from "/mo" or know
        // whether either is true. Asserting an operator string that no cadence
        // could have produced is the point: it proves the value came from the
        // package rather than from a BillingPeriod default. The corollary is
        // that a wrong suffix is a data bug, never a code one — four packages
        // carried a mislabelled "/mo" on a one-time price until this rule
        // changed and were cleared with it.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 399.00,
            // Cents on purpose: every other own-price fixture is a whole
            // dollar, so rounding to 0dp survived the suite until this changed.
            'sale_price' => 79.49,
            'price_suffix' => '/ea',
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 279.99,
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 79.49)
            ->assertJsonPath('data.price_from.suffix', '/ea')
            ->assertJsonPath('data.price_from.plan_id', null);
    }

    /**
     * A TIE MUST NOT SIGN SOMEONE UP TO A REBILL.
     *
     * Only reachable between two PLANS now that a package's own price pre-empts
     * every plan — but the reasoning is the same one the headline rule rests on.
     * Downstream the distinction is real: prescribe-rx reads a recurring plan as
     * a subscription, and on a local checkout the same choice decides whether
     * the merchant account starts auto-billing. So when two plans cost the same,
     * the figure must resolve to the one that does not rebill, rather than to
     * whichever the collection happened to hold first.
     */
    public function test_a_tie_between_two_plans_resolves_to_the_non_recurring_one(): void
    {
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 279.99,
            'is_recurring' => true,
        ]);
        $oneOff = Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 279.99,
            'is_recurring' => false,
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 279.99)
            ->assertJsonPath('data.price_from.plan_id', $oneOff->id);
    }

    /**
     * The tie-break must not become a preference. A plan that is genuinely
     * CHEAPER still wins, recurring or not — this rule orders equals, it does
     * not rank one-time purchases above better prices.
     */
    public function test_a_cheaper_recurring_plan_still_beats_a_dearer_one_off_plan(): void
    {
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        $cheaper = Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 279.99,
            'is_recurring' => true,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 349.00,
            'is_recurring' => false,
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 279.99)
            ->assertJsonPath('data.price_from.plan_id', $cheaper->id);
    }

    public function test_price_from_falls_back_to_any_cadence_when_nothing_is_monthly(): void
    {
        // A package sold only as a prepay term has no monthly price to lead
        // with. Rendering nothing would hide a purchasable stack, so it shows
        // the cheapest price there is, wearing that price's OWN suffix — the
        // card then says "From $899.00/6mo", which is true, rather than
        // "$899.00/mo", which is not.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        $semiAnnual = Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::SemiAnnual,
            'term_months' => 6,
            'retail_price' => 899.00,
            'price_suffix' => '/6mo',
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Annual,
            'term_months' => 12,
            'retail_price' => 1599.00,
            'price_suffix' => '/yr',
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 899)
            ->assertJsonPath('data.price_from.suffix', '/6mo')
            // The fallback path carries its plan id too, not just its figure.
            ->assertJsonPath('data.price_from.plan_id', $semiAnnual->id);
    }

    public function test_price_from_falls_back_to_the_cadence_suffix_when_the_operator_authored_none(): void
    {
        // price_suffix is free text and optional. With none authored the
        // BillingPeriod enum supplies it, so the unit is never simply missing.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'billing_period' => BillingPeriod::Monthly,
            'retail_price' => 120.00,
            'price_suffix' => null,
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', 120)
            ->assertJsonPath('data.price_from.suffix', '/mo');
    }

    public function test_price_from_is_null_amount_when_nothing_is_priced(): void
    {
        // No price anywhere must read as "no figure", never as $0.00 — the same
        // rule the range follows.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'retail_price' => null,
            'sale_price' => null,
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from.amount', null)
            ->assertJsonPath('data.price_from.suffix', null);
    }

    public function test_package_show_returns_404_for_draft(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Draft]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")->assertNotFound();
    }

    // ── Gap 8: is_on_sale ────────────────────────────────────────────────

    public function test_package_is_on_sale_true_when_any_plan_has_sale_price(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 199, 'sale_price' => 149]);

        $this->getJson('/api/v1/catalog/packages')
            ->assertOk()
            ->assertJsonPath('data.0.is_on_sale', true);
    }

    public function test_package_is_on_sale_false_when_no_plan_has_sale_price(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 199, 'sale_price' => null]);

        $this->getJson('/api/v1/catalog/packages')
            ->assertOk()
            ->assertJsonPath('data.0.is_on_sale', false);
    }

    // ── Gap 9: is_in_stock ───────────────────────────────────────────────

    public function test_package_response_includes_is_in_stock(): void
    {
        Package::factory()->create(['status' => CatalogStatus::Published, 'is_in_stock' => true]);

        $this->getJson('/api/v1/catalog/packages')
            ->assertOk()
            ->assertJsonPath('data.0.is_in_stock', true);
    }

    public function test_packages_filter_in_stock_excludes_out_of_stock(): void
    {
        Package::factory()->create(['status' => CatalogStatus::Published, 'is_in_stock' => true, 'name' => 'Available']);
        Package::factory()->create(['status' => CatalogStatus::Published, 'is_in_stock' => false, 'name' => 'Unavailable']);

        $this->getJson('/api/v1/catalog/packages?in_stock=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Available');
    }

    // ── Gap 7: price_min / price_max ─────────────────────────────────────

    public function test_packages_filter_price_min_uses_plan_effective_price(): void
    {
        $cheap = Package::factory()->create(['status' => CatalogStatus::Published, 'name' => 'Cheap']);
        Plan::factory()->create(['package_id' => $cheap->id, 'status' => CatalogStatus::Published, 'retail_price' => 50, 'sale_price' => null]);

        $expensive = Package::factory()->create(['status' => CatalogStatus::Published, 'name' => 'Expensive']);
        Plan::factory()->create(['package_id' => $expensive->id, 'status' => CatalogStatus::Published, 'retail_price' => 200, 'sale_price' => null]);

        $this->getJson('/api/v1/catalog/packages?price_min=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Expensive');
    }

    public function test_packages_filter_price_max_uses_plan_effective_price(): void
    {
        $cheap = Package::factory()->create(['status' => CatalogStatus::Published, 'name' => 'Cheap']);
        Plan::factory()->create(['package_id' => $cheap->id, 'status' => CatalogStatus::Published, 'retail_price' => 50, 'sale_price' => null]);

        $expensive = Package::factory()->create(['status' => CatalogStatus::Published, 'name' => 'Expensive']);
        Plan::factory()->create(['package_id' => $expensive->id, 'status' => CatalogStatus::Published, 'retail_price' => 200, 'sale_price' => null]);

        $this->getJson('/api/v1/catalog/packages?price_max=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cheap');
    }

    public function test_packages_price_filter_uses_sale_price_when_set(): void
    {
        // retail=$200 but on sale for $80 — should appear in price_max=100 results
        $package = Package::factory()->create(['status' => CatalogStatus::Published, 'name' => 'On Sale']);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 200, 'sale_price' => 80]);

        $this->getJson('/api/v1/catalog/packages?price_max=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'On Sale');
    }

    public function test_plan_billing_section_contains_subscription_fields(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published]);
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Published,
            'is_recurring' => true,
            'term_months' => 3,
            'rebill_strategy' => 'auto_renew',
        ]);

        $this->getJson("/api/v1/catalog/packages/{$package->slug}")
            ->assertOk()
            ->assertJsonPath('data.plans.0.billing.is_recurring', true)
            ->assertJsonPath('data.plans.0.billing.term_months', 3)
            ->assertJsonPath('data.plans.0.billing.rebill_strategy', 'auto_renew');
    }
}
