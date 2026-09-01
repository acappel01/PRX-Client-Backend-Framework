<?php

namespace Tests\Feature\Api\V1\Leads;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadEndpointTest extends TestCase
{
    use RefreshDatabase;

    // ─── POST /api/v1/leads ───────────────────────────────────────────

    public function test_can_create_lead_with_minimal_required_fields(): void
    {
        $response = $this->postJson('/api/v1/leads', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'status',
                    'first_name',
                    'last_name',
                    'email',
                    'address',
                    'consents',
                ],
            ])
            ->assertJsonPath('data.first_name', 'Jane')
            ->assertJsonPath('data.last_name', 'Smith')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('leads', ['email' => 'jane@example.com']);
    }

    public function test_lead_response_includes_handoff_url(): void
    {
        $response = $this->postJson('/api/v1/leads', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        $uuid = $response->json('data.uuid');

        $response->assertStatus(201)
            ->assertJsonPath('data.handoff_url', route('checkout.handoff', ['lead' => $uuid]));
    }

    public function test_creates_lead_with_all_optional_fields(): void
    {
        $response = $this->postJson('/api/v1/leads', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '555-1234',
            'date_of_birth' => '1985-04-15',
            'gender' => 'male',
            'address_line1' => '123 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
            'country' => 'US',
            'sms_consent' => true,
            'email_consent' => true,
            'checkout_path' => 'prx',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.phone', '555-1234')
            ->assertJsonPath('data.address.city', 'Austin')
            ->assertJsonPath('data.address.country', 'US')
            ->assertJsonPath('data.checkout_path', 'prx')
            ->assertJsonPath('data.consents.sms', true)
            ->assertJsonPath('data.consents.email', true);
    }

    /**
     * A distinct billing address must actually PERSIST. This repo has a
     * history of forms that save successfully and write nothing, so this
     * asserts the stored row rather than the response.
     */
    public function test_a_distinct_billing_address_round_trips(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.billing@example.test',
            'address_line1' => '4200 Guadalupe St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78751',
            'billing_same_as_shipping' => false,
            'billing_address_line1' => '900 Congress Ave',
            'billing_city' => 'Dallas',
            'billing_state' => 'TX',
            'billing_postal_code' => '75201',
        ])->assertCreated();

        $lead = Lead::where('email', 'dana.billing@example.test')->firstOrFail();

        $this->assertFalse($lead->billing_same_as_shipping);
        $this->assertSame('900 Congress Ave', $lead->billing_address_line1);
        $this->assertSame('Dallas', $lead->billing_city);
        $this->assertSame('75201', $lead->billing_postal_code);
    }

    /** Mirroring is the default, so billing columns stay empty. */
    public function test_billing_mirrors_shipping_by_default(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.mirror@example.test',
            'address_line1' => '4200 Guadalupe St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78751',
        ])->assertCreated();

        $lead = Lead::where('email', 'dana.mirror@example.test')->firstOrFail();

        $this->assertTrue($lead->billing_same_as_shipping);
        $this->assertNull($lead->billing_address_line1);
    }

    /**
     * An incomplete billing address is rejected at the edge rather than
     * assembled into a partial their validator would 422 on later.
     */
    public function test_an_incomplete_billing_address_is_rejected(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.partial@example.test',
            'address_line1' => '4200 Guadalupe St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78751',
            'billing_same_as_shipping' => false,
            'billing_address_line1' => '900 Congress Ave',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['billing_city', 'billing_state', 'billing_postal_code']);
    }

    /** Under-18 is refused here as well as by the provider's own rule. */
    public function test_an_under_18_date_of_birth_is_refused(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.minor@example.test',
            'date_of_birth' => now()->subYears(17)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['date_of_birth']);
    }

    /**
     * THE CART MUST ARRIVE AS IDENTIFIERS, NOT LABELS.
     *
     * The frontend used to send the cart API's DISPLAY shape —
     * `{type: "Product", name: …}`, where `type` is a class basename and the
     * line's `id` is the cart row rather than the product. Everything
     * downstream resolves on `resource_type` / `resource_id`, so the embed
     * looked up nothing, selected nothing, and rendered no clinical steps —
     * while the request returned 201 and the lead looked fine.
     *
     * A loose `['array']` rule is what let it through, so the shape is pinned
     * at the edge now.
     */
    public function test_a_cart_line_without_identifiers_is_refused(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.cart@example.test',
            'cart_items' => [
                ['type' => 'Product', 'name' => 'Sample Compound + B12', 'quantity' => 1],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cart_items.0.resource_type', 'cart_items.0.resource_id']);
    }

    public function test_a_cart_line_with_identifiers_is_stored_resolvably(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.cart2@example.test',
            'cart_items' => [
                ['resource_type' => 'product', 'resource_id' => 7, 'quantity' => 2, 'name' => 'Sample Compound + B12'],
            ],
        ])->assertCreated();

        $stored = Lead::where('email', 'dana.cart2@example.test')->firstOrFail()->cart_items;

        $this->assertSame('product', $stored[0]['resource_type']);
        $this->assertSame(7, $stored[0]['resource_id']);
    }

    /** An unknown resource type is a typo, not a new kind. */
    public function test_an_unknown_cart_resource_type_is_refused(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana.cart3@example.test',
            'cart_items' => [
                ['resource_type' => 'protocol', 'resource_id' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['cart_items.0.resource_type']);
    }

    public function test_consent_timestamp_set_when_either_consent_granted(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Ann',
            'last_name' => 'Lee',
            'email' => 'ann@example.com',
            'sms_consent' => true,
            'email_consent' => false,
        ])->assertStatus(201);

        $lead = Lead::where('email', 'ann@example.com')->sole();

        $this->assertNotNull($lead->consent_given_at);
    }

    public function test_consent_timestamp_null_when_no_consent_given(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Bob',
            'last_name' => 'Ray',
            'email' => 'bob@example.com',
            'sms_consent' => false,
            'email_consent' => false,
        ])->assertStatus(201);

        $lead = Lead::where('email', 'bob@example.com')->sole();

        $this->assertNull($lead->consent_given_at);
    }

    public function test_rejects_dob_under_18_years_old(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Young',
            'last_name' => 'Person',
            'email' => 'young@example.com',
            'date_of_birth' => now()->subYears(17)->format('Y-m-d'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_rejects_invalid_gender_value(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'gender' => 'alien',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['gender']);
    }

    public function test_rejects_country_not_two_characters(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'country' => 'USA',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['country']);
    }

    public function test_rejects_invalid_checkout_path(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'checkout_path' => 'stripe',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['checkout_path']);
    }

    public function test_ip_address_and_user_agent_captured(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Track',
            'last_name' => 'Me',
            'email' => 'track@example.com',
        ], ['User-Agent' => 'TestBrowser/1.0'])->assertStatus(201);

        $lead = Lead::where('email', 'track@example.com')->sole();

        $this->assertNotNull($lead->ip_address);
        $this->assertSame('TestBrowser/1.0', $lead->user_agent);
    }

    public function test_first_name_is_required(): void
    {
        $this->postJson('/api/v1/leads', [
            'last_name' => 'Doe',
            'email' => 'test@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['first_name']);
    }

    public function test_email_is_required(): void
    {
        $this->postJson('/api/v1/leads', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ─── GET /api/v1/leads/{uuid} ─────────────────────────────────────

    public function test_can_retrieve_lead_by_uuid(): void
    {
        $lead = Lead::factory()->create([
            'first_name' => 'Fetch',
            'last_name' => 'Me',
            'email' => 'fetch@example.com',
        ]);

        $this->getJson("/api/v1/leads/{$lead->uuid}")
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', $lead->uuid)
            ->assertJsonPath('data.email', 'fetch@example.com');
    }

    public function test_returns_404_for_unknown_uuid(): void
    {
        $this->getJson('/api/v1/leads/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function test_internal_fields_not_exposed_in_response(): void
    {
        $lead = Lead::factory()->create();

        $response = $this->getJson("/api/v1/leads/{$lead->uuid}");

        $data = $response->json('data');

        $this->assertArrayNotHasKey('ip_address', $data);
        $this->assertArrayNotHasKey('user_agent', $data);
        $this->assertArrayNotHasKey('prescribe_rx_encounter_id', $data);
        $this->assertArrayNotHasKey('prescribe_rx_patient_id', $data);
        $this->assertArrayNotHasKey('notes', $data);
    }
}
