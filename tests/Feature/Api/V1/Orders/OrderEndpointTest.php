<?php

namespace Tests\Feature\Api\V1\Orders;

use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderItem;
use App\Models\Commerce\OrderShipment;
use App\Models\Customer;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $patient = Patient::factory()->create();
        $this->customer = Customer::factory()->create(['portal_account_id' => $patient->id]);
        $this->withToken($patient->createToken('portal', ['patient:*'])->plainTextToken);
    }

    public function test_show_returns_order_by_uuid(): void
    {
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->create();

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $order->uuid)
            ->assertJsonPath('data.status', $order->status->value)
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_show_returns_404_for_unknown_uuid(): void
    {
        $this->getJson('/api/v1/orders/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_show_response_shape(): void
    {
        Order::factory()->state(['customer_id' => $this->customer->id])->create(['subtotal' => 149.00, 'total_amount' => 149.00]);
        $order = Order::first();

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'uuid', 'status', 'subtotal', 'tax_amount', 'shipping_amount',
                    'discount_amount', 'total_amount', 'currency',
                    'placed_at', 'shipped_at', 'delivered_at', 'cancelled_at',
                    'prescribe_rx_order_number', 'items', 'shipments',
                ],
            ]);
    }

    public function test_show_includes_items_when_present(): void
    {
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->create();
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Test Product', 'quantity' => 2]);

        $response = $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Test Product', $response->json('data.items.0.name'));
        $this->assertSame(2, $response->json('data.items.0.quantity'));
    }

    public function test_show_includes_shipments_when_present(): void
    {
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->shipped()->create();
        OrderShipment::factory()->create([
            'order_id' => $order->id,
            'carrier' => 'USPS',
            'tracking_number' => '9400111899223418527401',
        ]);

        $response = $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();

        $this->assertCount(1, $response->json('data.shipments'));
        $this->assertSame('USPS', $response->json('data.shipments.0.carrier'));
        $this->assertSame('9400111899223418527401', $response->json('data.shipments.0.tracking_number'));
    }

    public function test_show_does_not_expose_addresses(): void
    {
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->create([
            'shipping_address' => ['street' => '123 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
        ]);

        $response = $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();

        $this->assertArrayNotHasKey('shipping_address', $response->json('data'));
        $this->assertArrayNotHasKey('billing_address', $response->json('data'));
    }

    public function test_show_delivered_order_has_timestamps(): void
    {
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->delivered()->create();

        $response = $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();

        $this->assertNotNull($response->json('data.shipped_at'));
        $this->assertNotNull($response->json('data.delivered_at'));
        $this->assertNull($response->json('data.cancelled_at'));
    }

    public function test_show_cancelled_order_has_cancelled_at(): void
    {
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->cancelled()->create();

        $response = $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();

        $this->assertNotNull($response->json('data.cancelled_at'));
        $this->assertNull($response->json('data.delivered_at'));
    }

    public function test_show_works_for_order_without_prx_id(): void
    {
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->create(['encounter_id' => null, 'prescribe_rx_order_id' => null]);

        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
    }

    public function test_show_works_for_order_linked_to_encounter(): void
    {
        $encounter = Encounter::factory()->create();
        $order = Order::factory()->state(['customer_id' => $this->customer->id])->create(['encounter_id' => $encounter->id]);

        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
    }
}
