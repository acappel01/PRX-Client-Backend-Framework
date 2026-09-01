<?php

namespace Tests\Feature\PrescribeRx;

use App\Enums\Catalog\IntakeSelectionMode;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductType;
use App\Models\Lead;
use App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder;
use App\Settings\IntegrationSettings;
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

    private function builder(): PrxEmbedPayloadBuilder
    {
        return new PrxEmbedPayloadBuilder(app(IntegrationSettings::class));
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

    public function test_a_mapped_product_reaches_the_embed_as_its_prx_number(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => 'b3f1c2d4-0000-4000-8000-000000000002',
            'provider_product_sku' => 'PROD-20002',
        ]);

        $payload = $this->builder()->forLead($this->leadWithCart([
            ['resource_type' => 'product', 'resource_id' => $product->id],
        ]));

        $this->assertSame(['PROD-20002'], $payload['products']);
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

    public function test_a_mixed_cart_nominates_every_kind_at_once(): void
    {
        $package = Package::factory()->create(['provider_package_sku' => 'PKG-10005']);
        $product = Product::factory()->create(['provider_product_sku' => 'PROD-20005']);
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
        $this->assertSame(['PROD-20005'], $payload['products']);
        $this->assertSame(['b3f1c2d4-0000-4000-8000-000000000005'], $payload['planIds']);
    }
}
