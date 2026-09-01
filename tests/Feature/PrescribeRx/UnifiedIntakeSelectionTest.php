<?php

namespace Tests\Feature\PrescribeRx;

use App\Actions\Checkout\SubmitPrescribeRxCheckoutAction;
use App\Enums\Catalog\IntakeSelectionMode;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductType;
use App\Models\Commerce\Cart;
use App\Models\Lead;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the SHAPE of what reaches `POST /telehealth/intake/unified`.
 *
 * prescribe-rx owns the fact that a package contains products, plus the labs,
 * shipping and telehealth-consult behaviour keyed off the package row. Sending
 * the package by id delegates all of that back to them. The previous shape
 * flattened a package into member product ids and sent legacy `product_ids`,
 * so no package ever landed on an encounter and none of that machinery fired.
 *
 * These assert on the JSON actually put on the wire, because the defect was
 * invisible at every layer above it.
 */
class UnifiedIntakeSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('prescribe-rx.stub', false);

        $settings = app(IntegrationSettings::class);
        $settings->prescribe_rx_enabled = true;
        $settings->prescribe_rx_api_token = 'test-token';
        $settings->prescribe_rx_environment = 'sandbox';
        $settings->prescribe_rx_encounter_type_id = '019d2842-0000-4000-8000-00000000abcd';
        $settings->prescribe_rx_sales_org_id = '019d2842-0000-4000-8000-00000000ffff';

        Http::fake(['*/telehealth/intake/unified' => Http::response([
            'data' => [
                'encounter_id' => '019d5561-7b2b-7096-8d90-4ee49ef2ede8',
                'encounter_number' => 'ENC-1',
                'patient_chart_id' => '019d5561-7b24-7096-baa7-85485643b0a7',
                'patient_number' => 'PAT-1',
                'status' => 'pending_intake',
            ],
        ], 201)]);
    }

    private function lead(): Lead
    {
        return Lead::factory()->create([
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.reyes@example.test',
            'phone' => '5125550142',
            'date_of_birth' => '1988-04-02',
        ]);
    }

    /** @return array<string, mixed> */
    private function sentPayload(): array
    {
        $sent = null;

        Http::recorded(function ($request) use (&$sent) {
            if (str_contains($request->url(), '/telehealth/intake/unified')) {
                $sent = $request->data();
            }

            return true;
        });

        $this->assertNotNull($sent, 'No unified-intake request was sent.');

        return $sent;
    }

    public function test_a_package_is_sent_as_a_package_not_flattened_into_its_products(): void
    {
        $package = Package::factory()->create([
            'provider_package_id' => '019d2842-0000-4000-8000-000000000001',
        ]);
        $member = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-00000000dead',
        ]);
        $package->products()->attach($member->id, ['sort_order' => 1, 'is_included' => true]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'package',
            'itemable_id' => $package->id,
            'quantity' => 1,
            'unit_price_snapshot' => 399.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $payload = $this->sentPayload();

        $this->assertSame(
            [['package_id' => '019d2842-0000-4000-8000-000000000001']],
            $payload['packages'],
            'The package must be named, carrying no stray null identifiers.'
        );

        $this->assertArrayNotHasKey('product_ids', $payload, 'The legacy flattened shape must not be sent.');
        $this->assertArrayNotHasKey('products', $payload, 'A package must not also enumerate its members.');
    }

    public function test_a_chosen_term_rides_on_the_package_as_plan_id(): void
    {
        $package = Package::factory()->create([
            'provider_package_id' => '019d2842-0000-4000-8000-000000000002',
        ]);
        $plan = Plan::factory()->create([
            'package_id' => $package->id,
            'provider_plan_id' => '019d2842-0000-4000-8000-00000000p1a2',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'package',
            'itemable_id' => $package->id,
            'plan_id' => $plan->id,
            'quantity' => 1,
            'unit_price_snapshot' => 279.99,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $payload = $this->sentPayload();

        $this->assertSame('019d2842-0000-4000-8000-000000000002', $payload['packages'][0]['package_id']);
        $this->assertSame('019d2842-0000-4000-8000-00000000p1a2', $payload['packages'][0]['plan_id']);
    }

    public function test_a_product_is_sent_with_its_quantity_and_snapshot_price(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-000000000003',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 2,
            'unit_price_snapshot' => 149.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $payload = $this->sentPayload();

        $this->assertSame(
            [['product_id' => '019d2842-0000-4000-8000-000000000003', 'quantity' => 2, 'snapshot_price' => 149.0]],
            $payload['products']
        );
    }

    /**
     * Their selection lines accept EXACTLY ONE identifier. A spatie DTO
     * serialises its unset identifiers as explicit nulls, so without the
     * null-strip a line would carry all four and be rejected.
     */
    public function test_an_item_mapped_by_sku_alone_sends_the_number_and_no_null_id(): void
    {
        $package = Package::factory()->create([
            'provider_package_id' => null,
            'provider_package_sku' => 'PKG-1996370936',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'package',
            'itemable_id' => $package->id,
            'quantity' => 1,
            'unit_price_snapshot' => 399.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $this->assertSame(
            [['package_number' => 'PKG-1996370936']],
            $this->sentPayload()['packages']
        );
    }

    public function test_the_submission_carries_a_stable_idempotency_key(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-000000000004',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 99.00,
        ]);
        $lead = $this->lead();

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $lead);

        $expected = Str::slug((string) config('app.name')).'-'.$cart->ulid.'-'.$lead->uuid;

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $expected));
    }

    /**
     * Our lead form offers `prefer_not_to_say`; prescribe-rx accepts only
     * male / female / other and 422s on anything else. Declining to answer is
     * not the same as "other", so the value is DROPPED rather than mapped.
     *
     * Pinned because LeadFactory generates this value at random — an unpinned
     * factory value is a hidden input, and without this test deleting
     * mapGender() would fail nothing and 422 in production intermittently.
     */
    public function test_a_gender_their_api_rejects_is_dropped_rather_than_sent(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-000000000005',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 99.00,
        ]);

        $lead = $this->lead();
        $lead->update(['gender' => 'prefer_not_to_say']);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $lead->fresh());

        $this->assertArrayNotHasKey('gender', $this->sentPayload()['patient']);
    }

    public function test_a_gender_their_api_accepts_is_passed_through(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-000000000006',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 99.00,
        ]);

        $lead = $this->lead();
        $lead->update(['gender' => 'female']);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $lead->fresh());

        $this->assertSame('female', $this->sentPayload()['patient']['gender']);
    }

    /**
     * street2 is its own field on their side. Concatenating it into street
     * produced one unparseable line on the shipping label, and the shipping
     * address is what drives their state-licensing check.
     */
    public function test_a_second_address_line_is_sent_as_street2_not_concatenated(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-000000000007',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 99.00,
        ]);

        $lead = $this->lead();
        $lead->update([
            'address_line1' => '4200 Guadalupe St',
            'address_line2' => 'Apt 12B',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78751',
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $lead->fresh());

        $patient = $this->sentPayload()['patient'];

        $this->assertSame('4200 Guadalupe St', $patient['shipping_address']['street']);
        $this->assertSame('Apt 12B', $patient['shipping_address']['street2']);
        $this->assertArrayNotHasKey('address', $patient, 'One address shape is sent, never both.');
    }

    /**
     * The shipping address carries the state that decides which licensed
     * clinician can take the encounter, so it is sent as its own structured
     * field rather than folded into the legacy single-address shape.
     */
    public function test_billing_defaults_to_mirroring_shipping(): void
    {
        $payload = $this->submitWithLead(function ($lead) {
            $lead->update([
                'address_line1' => '4200 Guadalupe St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78751',
            ]);
        });

        $patient = $payload['patient'];

        $this->assertSame('TX', $patient['shipping_address']['state']);
        $this->assertTrue($patient['billing_same_as_shipping']);
        $this->assertArrayNotHasKey('billing_address', $patient);
    }

    public function test_a_distinct_billing_address_is_sent_alongside_shipping(): void
    {
        $payload = $this->submitWithLead(function ($lead) {
            $lead->update([
                'address_line1' => '4200 Guadalupe St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78751',
                'billing_same_as_shipping' => false,
                'billing_address_line1' => '900 Congress Ave',
                'billing_city' => 'Dallas',
                'billing_state' => 'TX',
                'billing_postal_code' => '75201',
            ]);
        });

        $patient = $payload['patient'];

        $this->assertSame('Austin', $patient['shipping_address']['city']);
        $this->assertSame('Dallas', $patient['billing_address']['city']);
        $this->assertFalse($patient['billing_same_as_shipping']);
    }

    /**
     * A partial address is worse than none — their validator 422s the whole
     * intake on an incomplete one, so it must not be assembled and sent.
     */
    public function test_an_incomplete_billing_address_is_not_sent_as_a_partial(): void
    {
        $payload = $this->submitWithLead(function ($lead) {
            $lead->update([
                'address_line1' => '4200 Guadalupe St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78751',
                'billing_same_as_shipping' => false,
                'billing_address_line1' => '900 Congress Ave',
                'billing_city' => null,
                'billing_state' => null,
                'billing_postal_code' => null,
            ]);
        });

        $this->assertArrayNotHasKey('billing_address', $payload['patient']);
    }

    /** @return array<string, mixed> */
    private function submitWithLead(callable $mutate): array
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-0000000000ad',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 99.00,
        ]);

        $lead = $this->lead();
        $mutate($lead);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $lead->fresh());

        return $this->sentPayload();
    }

    /**
     * Their server auto-flags test-looking names as sandbox. An explicit
     * `false` could override that, so production sends the key ABSENT — the
     * shape it had before the null-strip landed.
     */
    public function test_a_production_submission_does_not_deny_sandbox(): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->prescribe_rx_environment = 'production';

        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-000000000008',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 99.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $this->assertArrayNotHasKey('is_sandbox', $this->sentPayload());
    }

    /**
     * `answers` was always on the wire before the null-strip. An encounter
     * with no answers is legitimate (the embed collects them), so the key must
     * survive rather than the payload silently changing shape.
     */
    public function test_an_empty_answer_set_still_sends_the_answers_key(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-000000000009',
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 99.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $this->assertArrayHasKey('answers', $this->sentPayload());
    }

    /**
     * A product whose dose is provider-determined nominates its TYPE, so the
     * prescribing clinician picks the variant. Their line takes exactly one
     * identifier, so `product_id` must be absent entirely.
     */
    public function test_a_product_type_mode_product_sends_the_type_not_the_product(): void
    {
        $type = ProductType::factory()->create([
            'provider_product_type_id' => '019d2842-0000-4000-8000-0000000000t1',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'provider_product_id' => '019d2842-0000-4000-8000-00000000000c',
            'intake_selection_mode' => IntakeSelectionMode::ProductType,
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 149.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $line = $this->sentPayload()['products'][0];

        $this->assertSame('019d2842-0000-4000-8000-0000000000t1', $line['product_type_id']);
        $this->assertArrayNotHasKey('product_id', $line);
        $this->assertArrayNotHasKey('product_number', $line);
    }

    /**
     * THE RULE THAT MATTERS CLINICALLY: type mode never falls back to the
     * exact product. Falling back would pin a specific dose on an item that
     * exists precisely so a clinician can choose one. The line is dropped.
     */
    public function test_type_mode_with_an_unmapped_type_is_dropped_never_demoted_to_the_product(): void
    {
        $type = ProductType::factory()->create(['provider_product_type_id' => null]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'provider_product_id' => '019d2842-0000-4000-8000-00000000000d',
            'intake_selection_mode' => IntakeSelectionMode::ProductType,
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 149.00,
        ]);

        $this->expectException(\RuntimeException::class);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());
    }

    /** Default mode is unchanged behaviour — every existing row is this. */
    public function test_the_default_mode_still_sends_the_exact_product(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => '019d2842-0000-4000-8000-00000000000e',
        ]);

        $this->assertSame(IntakeSelectionMode::Product, $product->intake_selection_mode);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 149.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $this->assertSame('019d2842-0000-4000-8000-00000000000e', $this->sentPayload()['products'][0]['product_id']);
    }

    /**
     * Their line takes exactly one identifier, so the slug is a FALLBACK for a
     * type mapped without an id — never sent alongside one.
     *
     * The slug matters because a UUID is environment-specific: an id captured
     * against sandbox resolves to nothing against production and fails
     * SILENTLY, reporting "no products found" exactly as a malformed payload
     * would. This install was bitten by that.
     */
    public function test_a_product_type_falls_back_to_the_provider_slug(): void
    {
        $type = ProductType::factory()->create([
            'provider_product_type_id' => null,
            'provider_product_type_slug' => 'semaglutide-b12',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'intake_selection_mode' => IntakeSelectionMode::ProductType,
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 199.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $line = $this->sentPayload()['products'][0];

        $this->assertSame('semaglutide-b12', $line['product_type_slug']);
        $this->assertArrayNotHasKey('product_type_id', $line);
    }

    /** The id wins when both are mapped — one identifier per line. */
    public function test_a_product_type_prefers_the_id_over_the_slug(): void
    {
        $type = ProductType::factory()->create([
            'provider_product_type_id' => '01a057df-0000-4000-8000-0000000000b1',
            'provider_product_type_slug' => 'semaglutide-b12',
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $type->id,
            'intake_selection_mode' => IntakeSelectionMode::ProductType,
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'product',
            'itemable_id' => $product->id,
            'quantity' => 1,
            'unit_price_snapshot' => 199.00,
        ]);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());

        $line = $this->sentPayload()['products'][0];

        $this->assertSame('01a057df-0000-4000-8000-0000000000b1', $line['product_type_id']);
        $this->assertArrayNotHasKey('product_type_slug', $line);
    }

    public function test_an_unmapped_cart_is_refused_rather_than_submitted_empty(): void
    {
        $package = Package::factory()->create([
            'provider_package_id' => null,
            'provider_package_sku' => null,
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'itemable_type' => 'package',
            'itemable_id' => $package->id,
            'quantity' => 1,
            'unit_price_snapshot' => 399.00,
        ]);

        $this->expectException(\RuntimeException::class);

        app(SubmitPrescribeRxCheckoutAction::class)
            ->execute($cart->fresh(), $this->lead());
    }
}
