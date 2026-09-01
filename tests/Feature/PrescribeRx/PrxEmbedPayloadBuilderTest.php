<?php

namespace Tests\Feature\PrescribeRx;

use App\Enums\Catalog\IntakeSelectionMode;
use App\Enums\Payments\PaymentCollector;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductType;
use App\Models\Lead;
use App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder;
use App\Settings\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The embed payload is what actually nominates products inside the
 * prescribe-rx iframe. It shipped for months reading `prescribe_rx_*_number`
 * columns that do not exist — Eloquent returns null for a missing attribute
 * instead of raising, so `packages` / `products` / `planIds` were ALWAYS
 * empty and the embed opened with nothing selected. Nothing failed; nothing
 * logged.
 *
 * Every assertion here checks the arrays are POPULATED with the mapped
 * identifier, never merely that the key exists — "present but empty" is the
 * exact shape of the bug these tests exist to prevent.
 */
class PrxEmbedPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** Resolved through the container so new dependencies do not break every test. */
    private function builder(): PrxEmbedPayloadBuilder
    {
        return app(PrxEmbedPayloadBuilder::class);
    }

    private function leadWithCart(array $items): Lead
    {
        return Lead::factory()->create(['cart_items' => $items]);
    }

    public function test_a_mapped_package_reaches_the_embed_as_its_prx_number(): void
    {
        $package = Package::factory()->create([
            'provider_package_id' => 'b3f1c2d4-0000-4000-8000-000000000001',
            'provider_package_sku' => 'PKG-10001',
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'package', 'resource_id' => $package->id],
        ]));

        $this->assertSame(['PKG-10001'], $payload['packages']);
    }

    /**
     * THE UUID IS WHAT THE EMBED RESOLVES — measured against the live embed:
     * `?products=<uuid>` yields `initialProductIds: ["<uuid>"]` while
     * `?products=<sku>` yields `[]`, proven with a product from their own
     * production catalogue. This contradicts their SDK docblock, which
     * describes product NUMBERS; the measurement wins.
     */
    public function test_a_mapped_product_reaches_the_embed_as_its_uuid_not_its_sku(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => 'b3f1c2d4-0000-4000-8000-000000000002',
            'provider_product_sku' => 'GLPTIRZ-B12-17-0.5-2ML-VIALRECON',
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame(['b3f1c2d4-0000-4000-8000-000000000002'], $payload['products']);
    }

    /** A product mapped by SKU alone is still nominated rather than dropped. */
    public function test_a_product_mapped_by_sku_alone_falls_back_to_it(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => null,
            'provider_product_sku' => 'GLPTIRZ-B12-17-0.5-2ML-VIALRECON',
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame(['GLPTIRZ-B12-17-0.5-2ML-VIALRECON'], $payload['products']);
    }

    /**
     * At least one mapped SKU in the live catalogue carries a leading space,
     * which would defeat an exact match on their side.
     */
    public function test_a_padded_identifier_is_trimmed_before_it_is_sent(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => null,
            'provider_product_sku' => ' PEPNAD-1600-P3-3ML-PEN',
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame(['PEPNAD-1600-P3-3ML-PEN'], $payload['products']);
    }

    /**
     * `selectPlan(planId)` in the embed SDK takes an id, not a number, so the
     * id must win even when a sku is also mapped.
     */
    public function test_a_mapped_plan_reaches_the_embed_as_its_prx_id_not_its_sku(): void
    {
        $package = Package::factory()->create();
        $plan = Plan::factory()->create([
            'package_id' => $package->id,
            'provider_plan_id' => 'b3f1c2d4-0000-4000-8000-000000000003',
            'provider_plan_sku' => 'PLAN-30003',
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'plan', 'resource_id' => $plan->id],
        ]));

        $this->assertSame(['b3f1c2d4-0000-4000-8000-000000000003'], $payload['planIds']);
    }

    /**
     * The SDK's selectPackage/selectProducts take PKG-/PROD- numbers, so the
     * sku is preferred — but a package mapped by id alone must still be
     * nominated rather than silently dropped.
     */
    public function test_an_item_mapped_by_id_alone_still_reaches_the_embed(): void
    {
        $package = Package::factory()->create([
            'provider_package_id' => 'b3f1c2d4-0000-4000-8000-000000000004',
            'provider_package_sku' => null,
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'package', 'resource_id' => $package->id],
        ]));

        $this->assertSame(['b3f1c2d4-0000-4000-8000-000000000004'], $payload['packages']);
    }

    /**
     * The honest empty case: an unmapped package cannot be nominated, and
     * must not emit a null or an empty string into the SDK's array.
     */
    public function test_an_unmapped_package_is_dropped_rather_than_emitted_as_null(): void
    {
        $package = Package::factory()->create([
            'provider_package_id' => null,
            'provider_package_sku' => null,
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'package', 'resource_id' => $package->id],
        ]));

        $this->assertSame([], $payload['packages']);
    }

    /**
     * The embed drives type-mode products through `selectProductTypes()`, a
     * different SDK call from `selectProducts()` — hence a separate payload
     * key. A type-mode product must NOT also appear in `products`, or the
     * embed would nominate the item twice under conflicting identities.
     */
    public function test_a_type_mode_product_goes_to_product_types_and_not_products(): void
    {
        $type = ProductType::factory()->create([
            'provider_product_type_id' => '019d2842-0000-4000-8000-0000000000t9',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'provider_product_sku' => 'PROD-29999',
            'intake_selection_mode' => IntakeSelectionMode::ProductType,
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame([['product_type_id' => '019d2842-0000-4000-8000-0000000000t9']], $payload['productTypes']);
        $this->assertSame([], $payload['products']);
    }

    /** Type mode with an unmapped type is dropped, never demoted to the SKU. */
    public function test_a_type_mode_product_with_an_unmapped_type_is_dropped_entirely(): void
    {
        $type = ProductType::factory()->create(['provider_product_type_id' => null]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'provider_product_sku' => 'PROD-29998',
            'intake_selection_mode' => IntakeSelectionMode::ProductType,
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame([], $payload['productTypes']);
        $this->assertSame([], $payload['products']);
    }

    /**
     * The SDK serialises prefill into the iframe's QUERY STRING, so every
     * value must survive stringification. A nested address object used to be
     * emitted here and arrived as the literal `prefill_address=[object
     * Object]` — observed on the live handoff page.
     */
    public function test_prefill_carries_only_stringifiable_values(): void
    {
        $lead = Lead::factory()->create([
            'address_line1' => '4200 Guadalupe St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78751',
            'cart_items' => [],
        ]);

        $prefill = $this->builder()->forLead($lead)['prefill'];

        foreach ($prefill as $key => $value) {
            $this->assertIsNotArray($value, "prefill.{$key} is an array and would stringify to [object Object].");
            $this->assertIsNotObject($value, "prefill.{$key} is an object and would stringify to [object Object].");
        }

        $this->assertArrayNotHasKey('address', $prefill);
        $this->assertSame('4200 Guadalupe St', $prefill['address_line1']);
    }

    /**
     * WHO TAKES THE MONEY DECIDES WHICH STEPS RENDER. One setting drives both
     * sides, so the provider and the storefront cannot both believe they are
     * collecting — the failure mode that produces a double charge, or none.
     */
    public function test_the_providers_payment_step_renders_when_the_provider_collects(): void
    {
        $billing = app(BillingSettings::class);
        $billing->payment_collector = PaymentCollector::Provider->value;

        $skips = $this->builder()->forLead(Lead::factory()->create(['cart_items' => []]))['skipSteps'];

        $this->assertNotContains('checkout', $skips);
        $this->assertNotContains('payment', $skips);
    }

    public function test_the_providers_payment_step_is_skipped_when_we_collect(): void
    {
        $billing = app(BillingSettings::class);
        $billing->payment_collector = PaymentCollector::Storefront->value;

        $skips = $this->builder()->forLead(Lead::factory()->create(['cart_items' => []]))['skipSteps'];

        $this->assertContains('checkout', $skips);
        $this->assertContains('payment', $skips);
    }

    /** The personal-information step is always skipped — we collect it first. */
    public function test_the_personal_information_step_is_always_skipped(): void
    {
        $skips = $this->builder()->forLead(Lead::factory()->create(['cart_items' => []]))['skipSteps'];

        $this->assertContains('personal-information', $skips);
    }

    /** The embed takes exactly one of the two, same as the intake API. */
    public function test_the_embed_falls_back_to_the_provider_type_slug(): void
    {
        $type = ProductType::factory()->create([
            'provider_product_type_id' => null,
            'provider_product_type_slug' => 'semaglutide-b12',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'intake_selection_mode' => IntakeSelectionMode::ProductType,
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame([['product_type_slug' => 'semaglutide-b12']], $payload['productTypes']);
    }

    public function test_a_mixed_cart_nominates_every_kind_at_once(): void
    {
        $package = Package::factory()->create(['provider_package_sku' => 'PKG-10005']);
        $product = Product::factory()->create(['provider_product_id' => 'b3f1c2d4-0000-4000-8000-00000000dd05']);
        $plan = Plan::factory()->create([
            'package_id' => $package->id,
            'provider_plan_id' => 'b3f1c2d4-0000-4000-8000-000000000005',
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'package', 'resource_id' => $package->id],
            ['resource_type' => 'product', 'resource_id' => $product->id],
            ['resource_type' => 'plan', 'resource_id' => $plan->id],
        ]));

        $this->assertSame(['PKG-10005'], $payload['packages']);
        $this->assertSame(['b3f1c2d4-0000-4000-8000-00000000dd05'], $payload['products']);
        $this->assertSame(['b3f1c2d4-0000-4000-8000-000000000005'], $payload['planIds']);
    }
}
