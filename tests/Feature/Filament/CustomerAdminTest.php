<?php

namespace Tests\Feature\Filament;

use App\Actions\Customers\EnsureCustomerForPortalAccountAction;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('super_admin');
        $this->actingAs($operator);
    }

    public function test_customer_creation_does_not_enroll_a_portal_account(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['first_name' => 'Casey', 'last_name' => 'Shopper', 'email' => 'casey@example.test', 'date_of_birth' => '1990-04-02'])
            ->call('create')->assertHasNoFormErrors();

        $customer = Customer::sole();
        $this->assertSame('casey@example.test', $customer->email);
        $this->assertNull($customer->portal_account_id);
        $this->assertSame(0, Patient::count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotSame('casey@example.test', $customer->getRawOriginal('email'));
    }

    public function test_edit_hydrates_encrypted_fields_and_preserves_portal_credentials_and_provider_references(): void
    {
        $account = Patient::factory()->create(['email' => 'login@example.test', 'prx_patient_chart_id' => 'chart-123']);
        $token = $account->createToken('existing', ['patient:*'])->plainTextToken;
        $customer = app(EnsureCustomerForPortalAccountAction::class)->execute($account, 'sandbox');
        $password = $account->password;

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()->assertFormSet(['email' => 'login@example.test', 'first_name' => $customer->first_name])
            ->fillForm(['email' => 'contact@example.test', 'phone' => '+15555550100'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('contact@example.test', $customer->refresh()->email);
        $this->assertSame('chart-123', $customer->prx_patient_chart_id);
        $this->assertSame('sandbox', $customer->provider_environment);
        $this->assertSame('login@example.test', $account->refresh()->email);
        $this->assertSame($password, $account->password);
        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_customer_list_and_detail_render_without_provider_calls(): void
    {
        $customer = Customer::factory()->create();
        Livewire::test(ListCustomers::class)->assertOk()->assertCanSeeTableRecords([$customer]);
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])->assertOk()->assertSee($customer->full_name);
        $this->assertFalse(CustomerResource::canGloballySearch());
    }

    public function test_shipping_and_billing_addresses_can_be_saved_from_customer_view(): void
    {
        $customer = Customer::factory()->create();
        foreach (['shipping', 'billing'] as $kind) {
            Livewire::test(AddressesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
                ->callTableAction('addAddress', data: [
                    'kind' => $kind,
                    'address' => ['line1' => '42 Example Road', 'city' => 'Testville', 'country_code' => 'US'],
                    'is_default' => true,
                ])->assertHasNoTableActionErrors();
        }
        $this->assertSame(2, $customer->addresses()->count());
        $this->assertSame(2, $customer->addresses()->where('is_default', true)->count());
        $address = $customer->addresses()->first();
        Livewire::test(AddressesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->callTableAction('editAddress', $address, data: [
                'kind' => $address->kind,
                'address' => ['line1' => '84 Changed Road', 'city' => 'Testville', 'country_code' => 'US'],
                'is_default' => true,
            ])->assertHasNoTableActionErrors();
        $this->assertSame('84 Changed Road', $address->refresh()->address['line1']);
    }

    public function test_staff_without_customer_permission_cannot_open_the_customer_screens(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        Livewire::test(ListCustomers::class)->assertForbidden();
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])->assertForbidden();
        Livewire::test(CreateCustomer::class)->assertForbidden();
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->assertForbidden();
    }

    public function test_customer_view_permission_does_not_grant_account_security_or_address_editing(): void
    {
        $account = Patient::factory()->create();
        $customer = app(EnsureCustomerForPortalAccountAction::class)->execute($account);
        $viewer = User::factory()->create();
        foreach (['ViewAny:Customer', 'View:Customer'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $viewer->givePermissionTo($permission);
        }
        $this->actingAs($viewer);
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()->assertActionHidden('portalAccount')->assertActionHidden('edit');
        Livewire::test(AddressesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertTableActionHidden('addAddress');
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->assertForbidden();
    }

    public function test_invalid_contact_fields_report_form_errors_without_creating_a_customer(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['first_name' => str_repeat('x', 101), 'last_name' => 'Customer', 'phone' => str_repeat('1', 33), 'date_of_birth' => today()->addDay()->toDateString()])
            ->call('create')
            ->assertHasFormErrors(['first_name', 'phone', 'date_of_birth']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_invalid_address_reports_action_field_errors_without_persisting(): void
    {
        $customer = Customer::factory()->create();
        Livewire::test(AddressesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->callTableAction('addAddress', data: [
                'kind' => 'shipping',
                'address' => ['line1' => '42 Example Road', 'city' => str_repeat('x', 101), 'country_code' => '12'],
                'is_default' => true,
            ])->assertHasTableActionErrors(['address.city', 'address.country_code']);
        $this->assertDatabaseCount('customer_addresses', 0);
    }
}
