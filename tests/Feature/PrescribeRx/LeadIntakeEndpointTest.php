<?php

namespace Tests\Feature\PrescribeRx;

use App\Enums\LeadStatus;
use App\Enums\Payments\LeadPaymentStatus;
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
        $billing->payment_collector = PaymentCollector::CaptureThenIntake->value;

        // Settled, because when this side collects, an unpaid lead is refused
        // outright — see the gate tests below.
        $lead = Lead::factory()->create([
            'cart_items' => [],
            'payment_status' => LeadPaymentStatus::Captured,
        ]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/intake")
            ->assertOk()
            ->assertJsonPath('data.payment.collector', 'capture_then_intake')
            ->assertJsonPath('data.payment.collect_on_site', true);
    }

    /**
     * THE GATE, and it is why the payment state is recorded at all.
     *
     * A declined card that still reached a clinical intake would produce a
     * completed encounter: a clinician's time spent, and product shipped, for
     * money nobody took. Enforced on the ENDPOINT rather than only in the page,
     * because the page is a URL anyone holding the lead uuid can open.
     */
    public function test_an_unpaid_lead_cannot_reach_the_intake_when_we_collect(): void
    {
        $billing = app(BillingSettings::class);
        $billing->payment_collector = PaymentCollector::CaptureThenIntake->value;

        $lead = Lead::factory()->create(['cart_items' => [], 'payment_status' => LeadPaymentStatus::None]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/intake")
            ->assertStatus(402)
            ->assertJsonPath('errors.payment.0', 'Payment must be completed before the medical intake.');
    }

    public function test_a_failed_card_cannot_reach_the_intake(): void
    {
        $billing = app(BillingSettings::class);
        $billing->payment_collector = PaymentCollector::CaptureThenIntake->value;

        $lead = Lead::factory()->create(['cart_items' => [], 'payment_status' => LeadPaymentStatus::Failed]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/intake")->assertStatus(402);
    }

    /** An authorisation is enough: the provider captures after the encounter. */
    public function test_an_authorised_card_may_reach_the_intake(): void
    {
        $billing = app(BillingSettings::class);
        $billing->payment_collector = PaymentCollector::AuthorizeThenCapture->value;

        $lead = Lead::factory()->create(['cart_items' => [], 'payment_status' => LeadPaymentStatus::Authorized]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/intake")->assertOk();
    }

    /**
     * With the provider collecting there is nothing for us to gate on — their
     * embed takes the card inside its own checkout step.
     */
    public function test_the_gate_does_not_apply_when_the_provider_collects(): void
    {
        $billing = app(BillingSettings::class);
        $billing->payment_collector = PaymentCollector::Provider->value;

        $lead = Lead::factory()->create(['cart_items' => [], 'payment_status' => LeadPaymentStatus::None]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/intake")->assertOk();
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
