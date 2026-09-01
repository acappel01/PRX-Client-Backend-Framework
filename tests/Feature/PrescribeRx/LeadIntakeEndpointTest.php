<?php

namespace Tests\Feature\PrescribeRx;

use App\Enums\LeadStatus;
use App\Enums\Payments\PaymentCollector;
use App\Models\Lead;
use App\Settings\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront hosts the clinical embed, so everything it needs to do that
 * arrives over the API rather than being rendered into a Blade page on the
 * admin domain.
 */
class LeadIntakeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_embed_config_cart_and_payment_mode(): void
    {
        $lead = Lead::factory()->create([
            'cart_items' => [
                ['resource_type' => 'product', 'resource_id' => 1, 'quantity' => 2, 'name' => 'Sample Compound'],
            ],
            'cart_subtotal' => 798.00,
        ]);

        $response = $this->getJson("/api/v1/leads/{$lead->uuid}/intake")->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'embed' => ['embedCode', 'prefill', 'packages', 'products', 'productTypes', 'skipSteps'],
                'environment',
                'payment' => ['collector', 'collect_on_site'],
                'cart' => ['items', 'subtotal'],
            ],
        ]);

        // JSON encodes a whole decimal as an int, so compare the value, not its type.
        $this->assertEquals(798.0, $response->json('data.cart.subtotal'));
        $this->assertSame('Sample Compound', $response->json('data.cart.items.0.name'));
        $this->assertSame(2, $response->json('data.cart.items.0.quantity'));
    }

    /**
     * One setting drives both sides, so the storefront and the provider can
     * never both believe they are collecting.
     */
    public function test_the_payment_mode_reflects_the_billing_setting(): void
    {
        $billing = app(BillingSettings::class);
        $billing->payment_collector = PaymentCollector::Storefront->value;

        $lead = Lead::factory()->create(['cart_items' => []]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/intake")
            ->assertOk()
            ->assertJsonPath('data.payment.collector', 'storefront')
            ->assertJsonPath('data.payment.collect_on_site', true);
    }

    public function test_an_unknown_lead_is_a_404_not_an_empty_payload(): void
    {
        $this->getJson('/api/v1/leads/00000000-0000-4000-8000-000000000000/intake')->assertNotFound();
    }

    /**
     * The advisory ping exists so the storefront can show a thank-you state
     * without waiting on the webhook. It is NOT authoritative — but it must
     * actually move the lead, or it is decoration.
     */
    public function test_the_completion_ping_marks_the_lead_handed_off(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::New_->value, 'cart_items' => []]);

        $this->postJson("/api/v1/leads/{$lead->uuid}/intake/complete", [
            'encounter_id' => 'enc-123',
            'patient_id' => 'pat-456',
        ])->assertOk()->assertJsonPath('data.ok', true);

        $lead->refresh();

        // `status` is stored as a plain string on this model, not cast.
        $this->assertSame(LeadStatus::HandedOff->value, (string) $lead->status);
        $this->assertSame('enc-123', $lead->prescribe_rx_encounter_id);
        $this->assertSame('pat-456', $lead->prescribe_rx_patient_id);
    }

    public function test_the_completion_ping_rejects_an_unknown_lead(): void
    {
        $this->postJson('/api/v1/leads/00000000-0000-4000-8000-000000000000/intake/complete', [])
            ->assertNotFound();
    }
}
