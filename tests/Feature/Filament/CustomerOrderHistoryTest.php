<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Commerce\Orders\Pages\ListOrders;
use App\Filament\Resources\Commerce\Orders\Pages\ViewOrder;
use App\Filament\Resources\Commerce\Orders\RelationManagers\ShipmentsRelationManager;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Models\Commerce\Cart;
use App\Models\Commerce\CheckoutAttempt;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderShipment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        $this->actingAs($user);

        return $user;
    }

    private function superAdmin(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);
    }

    public function test_customer_only_viewer_cannot_load_order_history_or_direct_order_detail(): void
    {
        $this->staff(['ViewAny:Customer', 'View:Customer']);
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Livewire::test(ViewCustomer::class, ['record' => $customer->uuid])->assertOk()->assertDontSee($order->uuid);
        $this->assertFalse(OrdersRelationManager::canViewForRecord($customer, ViewCustomer::class));
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])->assertForbidden();
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])->assertForbidden();
    }

    public function test_history_requires_order_list_and_record_permissions_and_shows_only_active_owned_orders(): void
    {
        $viewer = $this->staff(['ViewAny:Customer', 'View:Customer', 'ViewAny:Order']);
        $customer = Customer::factory()->create();
        $this->assertFalse(OrdersRelationManager::canViewForRecord($customer, ViewCustomer::class));
        Permission::findOrCreate('View:Order', 'web');
        $viewer->givePermissionTo('View:Order');
        $owned = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'EUR', 'total_amount' => '12.50']);
        $other = Order::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $unassigned = Order::factory()->create();
        $deleted = Order::factory()->create(['customer_id' => $customer->id]);
        $deleted->delete();
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertOk()->assertCanSeeTableRecords([$owned])->assertCanNotSeeTableRecords([$other, $unassigned, $deleted])
            ->assertTableActionExists('viewOrder', record: $owned)
            ->assertTableActionDoesNotExist('edit', record: $owned)
            ->assertSee('€12.50');
    }

    public function test_order_detail_is_read_only_and_exposes_only_attempt_summary(): void
    {
        $this->superAdmin();
        $order = Order::factory()->create(['currency' => 'EUR', 'total_amount' => '12.50', 'metadata' => ['private' => 'secret-order-metadata']]);
        $order->items()->create(['name' => 'Frozen purchase', 'quantity' => 1, 'unit_price' => '12.50', 'line_total' => '12.50']);
        $shipment = OrderShipment::factory()->create(['order_id' => $order->id, 'metadata' => ['private' => 'secret-shipment-metadata']]);
        $cart = Cart::factory()->create();
        $lead = Lead::factory()->create(['cart_ulid' => $cart->ulid]);
        CheckoutAttempt::create([
            'uuid' => (string) Str::uuid(), 'cart_id' => $cart->id, 'lead_id' => $lead->id, 'order_id' => $order->id,
            'status' => 'unknown', 'provider_idempotency_key' => 'secret-provider-key',
            'request_fingerprint' => str_repeat('a', 64), 'answers_fingerprint' => str_repeat('b', 64),
            'cart_fingerprint' => str_repeat('c', 64), 'provider_environment' => 'sandbox',
            'submitted_at' => now(), 'provider_receipt' => ['patient_id' => 'secret-receipt-chart'],
            'result' => ['private' => 'secret-result-data'],
        ]);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertOk()->assertSee('unknown')->assertSee('Frozen purchase')->assertSee('€12.50')
            ->assertSee('not that payment was confirmed')->assertSet('data', [])
            ->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete');
        foreach (['secret-order-metadata', 'secret-shipment-metadata', 'secret-receipt-chart', 'secret-result-data', 'secret-provider-key', str_repeat('a', 64)] as $secret) {
            $page->assertDontSee($secret);
        }
        $this->assertSame([], $page->instance()->getRelationManagers());
        $before = $order->fresh()->getAttributes();
        $page->call('mountAction', 'edit')->call('mountAction', 'delete');
        $this->assertSame($before, $order->fresh()->getAttributes());
        // Even a crafted shipment relation mount in this view cannot mutate it.
        $shipmentBefore = $shipment->fresh()->getAttributes();
        Livewire::test(ShipmentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertTableActionHidden('edit', $shipment)
            ->mountTableAction('edit', $shipment)
            ->call('callMountedAction');
        $this->assertSame($shipmentBefore, $shipment->fresh()->getAttributes());
    }

    public function test_order_view_permission_does_not_require_update_and_deleted_orders_stay_out_of_new_view(): void
    {
        $this->staff(['ViewAny:Order', 'View:Order']);
        $order = Order::factory()->create();
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])->assertOk()->assertActionDoesNotExist('edit');
        $order->delete();
        $deletedList = Livewire::test(ListOrders::class)->filterTable('trashed', true)->assertTableActionHidden('view', $order);
        $this->assertNull($deletedList->instance()->getTable()->getRecordUrl($order));
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])->assertNotFound();
    }

    public function test_orders_list_searches_local_uuid_and_shows_explicit_owner_without_contact_leak(): void
    {
        $this->staff(['ViewAny:Order', 'View:Order']);
        $customer = Customer::factory()->create(['email' => 'hidden-customer-contact@example.test']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'prescribe_rx_order_number' => null, 'currency' => 'EUR', 'total_amount' => '12.50']);
        $other = Order::factory()->create();
        Livewire::test(ListOrders::class)->assertOk()->searchTable($order->uuid)
            ->assertCanSeeTableRecords([$order])->assertCanNotSeeTableRecords([$other])
            ->assertSee($customer->uuid)->assertDontSee('hidden-customer-contact@example.test')->assertSee('€12.50');
    }

    public function test_order_history_rechecks_revoked_permissions_on_a_livewire_requery(): void
    {
        $viewer = $this->staff(['ViewAny:Customer', 'View:Customer', 'ViewAny:Order', 'View:Order']);
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $history = Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$order]);
        $viewer->revokePermissionTo('View:Order');
        $history->call('$refresh')->assertForbidden();
    }

    public function test_list_only_permission_does_not_expose_new_detail_action_or_row_link(): void
    {
        $this->staff(['ViewAny:Order']);
        $order = Order::factory()->create();
        $list = Livewire::test(ListOrders::class)->assertOk()->assertTableActionHidden('view', $order);
        $this->assertNull($list->instance()->getTable()->getRecordUrl($order));
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])->assertForbidden();
    }
}
