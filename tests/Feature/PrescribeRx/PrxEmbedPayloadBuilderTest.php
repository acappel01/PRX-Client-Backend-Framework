<?php

namespace Tests\Feature\PrescribeRx;

use App\Enums\Catalog\IntakeSelectionMode;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductClass;
use App\Models\Catalog\ProductType;
use App\Models\Lead;
use App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder;
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
     * THE PREFILL KEY NAMES ARE THEIRS, NOT OURS. Their vocabulary is
     * `address` / `city` / `state` / `zip`; this used to send `address_line1`,
     * `postal_code` and `country`, none of which they document — so the whole
     * address silently failed to prefill while every other field worked.
     */
    public function test_prefill_uses_the_providers_address_vocabulary(): void
    {
        $lead = Lead::factory()->create([
            'address_line1' => '4200 Guadalupe St',
            'address_line2' => null,
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78751',
            'cart_items' => [],
        ]);

        $prefill = $this->builder()->forLead($lead)['prefill'];

        $this->assertSame('4200 Guadalupe St', $prefill['address']);
        $this->assertSame('78751', $prefill['zip']);
        $this->assertSame('Austin', $prefill['city']);
        $this->assertSame('TX', $prefill['state']);

        foreach (['address_line1', 'address_line2', 'postal_code', 'country'] as $ours) {
            $this->assertArrayNotHasKey($ours, $prefill, "{$ours} is our column name, not a key they read.");
        }
    }

    /**
     * Their flat vocabulary has one street key and no `street2`, so a second
     * line is joined rather than dropped — a missing apartment number is a
     * mis-delivered prescription. The intake API takes the opposite approach
     * because there `street2` is its own field.
     */
    public function test_a_second_address_line_is_joined_into_the_street(): void
    {
        $lead = Lead::factory()->create([
            'address_line1' => '4200 Guadalupe St',
            'address_line2' => 'Apt 12B',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78751',
            'cart_items' => [],
        ]);

        $this->assertSame(
            '4200 Guadalupe St, Apt 12B',
            $this->builder()->forLead($lead)['prefill']['address']
        );
    }

    /**
     * Prefill travels by postMessage, so a nested value would survive — but
     * every key here is flat by their contract, and a stray array would be a
     * sign someone reintroduced a shape they do not read.
     */
    public function test_prefill_carries_only_scalar_values(): void
    {
        $lead = Lead::factory()->create(['cart_items' => []]);

        foreach ($this->builder()->forLead($lead)['prefill'] as $key => $value) {
            $this->assertIsNotArray($value, "prefill.{$key} should be scalar.");
        }
    }

    /**
     * A class is one level broader than a type: the clinician picks any product
     * of any type within it, and a step gated on `for_product_class_ids`
     * renders. It is a separate SDK call, hence a separate payload key.
     */
    public function test_a_class_mode_product_goes_to_product_classes(): void
    {
        $class = ProductClass::factory()->create([
            'provider_product_class_id' => '019b8f02-0000-4000-8000-0000000000c1',
        ]);
        $type = ProductType::factory()->create(['product_class_id' => $class->id]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'provider_product_id' => 'b3f1c2d4-0000-4000-8000-0000000000ff',
            'intake_selection_mode' => IntakeSelectionMode::ProductClass,
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame([['product_class_id' => '019b8f02-0000-4000-8000-0000000000c1']], $payload['productClasses']);
        $this->assertSame([], $payload['products']);
        $this->assertSame([], $payload['productTypes']);
    }

    /** An unmapped class is dropped, never demoted to the concrete product. */
    public function test_class_mode_with_an_unmapped_class_is_dropped(): void
    {
        $class = ProductClass::factory()->create([
            'provider_product_class_id' => null,
            'provider_product_class_slug' => null,
        ]);
        $type = ProductType::factory()->create(['product_class_id' => $class->id]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'provider_product_id' => 'b3f1c2d4-0000-4000-8000-0000000000fe',
            'intake_selection_mode' => IntakeSelectionMode::ProductClass,
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame([], $payload['productClasses']);
        $this->assertSame([], $payload['products']);
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
