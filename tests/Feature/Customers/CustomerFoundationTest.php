<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Customers\EnsureCustomerForPortalAccountAction;
use App\Actions\Customers\SaveCustomerAddressAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Data\Customers\CreateCustomerData;
use App\Data\Customers\UpdateCustomerData;
use App\Data\Customers\UpsertCustomerAddressData;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_contact_is_encrypted_and_creating_commerce_does_not_create_an_account(): void
    {
        $data = CreateCustomerData::validateAndCreate([
            'first_name' => 'Example', 'last_name' => 'Customer',
            'email' => 'customer@example.test', 'phone' => '5551234567', 'date_of_birth' => '1990-04-12',
        ]);
        $customer = app(CreateCustomerAction::class)->execute($data)->fresh();
        $stored = (array) DB::table('customers')->where('id', $customer->id)->first();

        foreach (['first_name', 'last_name', 'email', 'phone', 'date_of_birth'] as $field) {
            $this->assertSame($data->{$field}, $customer->{$field});
            $this->assertNotSame($data->{$field}, $stored[$field]);
            $this->assertStringNotContainsString($data->{$field}, $stored[$field]);
            $this->assertArrayNotHasKey($field, $customer->toArray());
        }
        $this->assertSame('Example Customer', $customer->full_name);
        $this->assertNull($customer->portal_account_id);
        $this->assertNull($customer->provider_environment);
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('uuid', $customer->getRouteKeyName());
    }

    public function test_explicit_account_provisioning_is_idempotent_and_never_overwrites_customer_or_account(): void
    {
        $patient = Patient::factory()->withPrxChart()->create();
        $session = $patient->createToken('existing-session');
        $original = $patient->fresh()->getRawOriginal();
        $action = app(EnsureCustomerForPortalAccountAction::class);
        $customer = $action->execute($patient, 'sandbox');
        $this->assertSame($patient->id, $customer->portalAccount->id);
        $this->assertSame($patient->prx_patient_chart_id, $customer->prx_patient_chart_id);
        $this->assertSame($patient->prx_patient_number, $customer->prx_patient_number);
        $this->assertSame('sandbox', $customer->provider_environment);
        foreach (['portal_account_id', 'provider_environment', 'prx_patient_chart_id', 'prx_patient_id', 'prx_patient_number'] as $field) {
            $this->assertArrayNotHasKey($field, $customer->toArray());
        }

        app(UpdateCustomerAction::class)->execute($customer, UpdateCustomerData::validateAndCreate([
            'first_name' => 'Commerce name', 'last_name' => 'Only', 'email' => 'contact@example.test',
        ]));
        $again = $action->execute($patient);
        $this->assertSame($customer->id, $again->id);
        $this->assertSame('Commerce name', $again->first_name);
        $this->assertSame($patient->prx_patient_chart_id, $again->prx_patient_chart_id);
        $this->assertSame('sandbox', $again->provider_environment);
        $this->assertSame($original, $patient->fresh()->getRawOriginal());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $session->accessToken->id]);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_matching_contact_email_does_not_link_or_merge_an_account(): void
    {
        $patient = Patient::factory()->create();
        $unlinked = Customer::factory()->create(['email' => $patient->email]);
        $linked = app(EnsureCustomerForPortalAccountAction::class)->execute($patient);

        $this->assertNotSame($unlinked->id, $linked->id);
        $this->assertNull($unlinked->fresh()->portal_account_id);
        $this->assertNull($patient->fresh()->email_verified_at);
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_deleted_customer_is_not_restored_or_replaced_by_provisioning(): void
    {
        $patient = Patient::factory()->create();
        $action = app(EnsureCustomerForPortalAccountAction::class);
        $customer = $action->execute($patient);
        $customer->delete();

        try {
            $action->execute($patient);
            $this->fail('A deleted customer must be restored deliberately.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }
        $this->assertSame(1, Customer::withTrashed()->count());
        $this->assertTrue($customer->fresh()->trashed());
    }

    public function test_addresses_are_encrypted_and_default_switch_is_per_customer_and_kind(): void
    {
        $customer = Customer::factory()->create();
        $other = CustomerAddress::factory()->create(['is_default' => true]);
        $save = app(SaveCustomerAddressAction::class);
        $first = $save->execute($customer, $this->addressData('shipping'));
        $billing = $save->execute($customer, $this->addressData('billing'));
        $second = $save->execute($customer, $this->addressData('shipping', '456 Example Street'));

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($billing->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($other->fresh()->is_default);
        $this->assertSame('US', $second->address['country_code']);
        $this->assertSame('456 Example Street', $second->address['line1']);
        $this->assertStringNotContainsString('456 Example Street', $second->getRawOriginal('address'));
        $this->assertArrayNotHasKey('address', $second->toArray());

        $updated = $save->execute($customer, $this->addressData('billing', '789 Updated Street'), $first);
        $this->assertSame($first->id, $updated->id);
        $this->assertFalse($billing->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(3, $customer->addresses()->count());
    }

    public function test_saving_another_customers_address_is_refused_without_changing_any_defaults(): void
    {
        $customer = Customer::factory()->create();
        $own = CustomerAddress::factory()->for($customer)->create(['is_default' => true]);
        $foreign = CustomerAddress::factory()->create(['is_default' => true]);

        try {
            app(SaveCustomerAddressAction::class)->execute($customer, $this->addressData('shipping'), $foreign);
            $this->fail('A foreign address must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('address', $e->errors());
        }
        $this->assertTrue($own->fresh()->is_default);
        $this->assertTrue($foreign->fresh()->is_default);
        $this->assertDatabaseCount('customer_addresses', 2);
    }

    public function test_address_contract_refuses_unknown_fields_and_invalid_shapes(): void
    {
        $this->expectException(ValidationException::class);
        UpsertCustomerAddressData::validateAndCreate([
            'kind' => 'shipping',
            'address' => ['line1' => '123 Example Street', 'city' => 'Example', 'country_code' => 'USA', 'patient_id' => 123],
        ]);
    }

    public function test_customer_and_legacy_account_order_relations_are_independent(): void
    {
        $patient = Patient::factory()->create();
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'patient_id' => $patient->id]);

        $this->assertSame($order->id, $patient->orders()->sole()->id);
        $this->assertSame($order->id, $customer->orders()->sole()->id);
        $this->assertSame($patient->id, $order->patient->id);
        $this->assertSame($customer->id, $order->customer->id);
        $this->assertNull($customer->portal_account_id);
    }

    public function test_customer_contract_does_not_accept_future_birthdates(): void
    {
        $this->expectException(ValidationException::class);
        CreateCustomerData::validateAndCreate([
            'first_name' => 'Example', 'last_name' => 'Customer', 'date_of_birth' => now()->addDay()->toDateString(),
        ]);
    }

    public function test_removing_an_account_does_not_delete_customer_or_commercial_history(): void
    {
        $patient = Patient::factory()->create();
        $customer = app(EnsureCustomerForPortalAccountAction::class)->execute($patient);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'patient_id' => $patient->id]);
        $address = CustomerAddress::factory()->for($customer)->create();

        $patient->forceDelete();

        $this->assertNull($customer->fresh()->portal_account_id);
        $this->assertNull($order->fresh()->patient_id);
        $this->assertSame($customer->id, $order->fresh()->customer_id);
        $this->assertNotNull($address->fresh());
    }

    private function addressData(string $kind, string $line1 = '123 Example Street'): UpsertCustomerAddressData
    {
        return UpsertCustomerAddressData::validateAndCreate([
            'kind' => $kind,
            'address' => ['line1' => $line1, 'city' => 'Example City', 'country_code' => 'us'],
            'is_default' => true,
        ]);
    }
}
