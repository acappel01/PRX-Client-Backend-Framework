<?php

namespace Tests\Feature\Api\V1\Orders;

use App\Models\ApiClient;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function ownedOrder(Patient $patient): Order
    {
        $customer = Customer::factory()->create(['portal_account_id' => $patient->id]);

        return Order::factory()->create(['customer_id' => $customer->id]);
    }

    private function show(Order $order, ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/orders/'.$order->uuid,
            $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    public function test_uuid_alone_is_not_authorization_for_existing_or_missing_orders(): void
    {
        $order = $this->ownedOrder(Patient::factory()->create());
        $this->show($order)->assertUnauthorized();
        $order->uuid = '00000000-0000-0000-0000-000000000000';
        $this->show($order)->assertUnauthorized();
    }

    public function test_operator_partner_and_machine_tokens_cannot_read_customer_orders_even_with_patient_abilities(): void
    {
        $order = $this->ownedOrder(Patient::factory()->create());
        $operator = User::factory()->create();
        $partner = User::factory()->create();
        $partner->assignRole(Role::findOrCreate('partner', 'web'));
        $machine = ApiClient::factory()->create();

        foreach ([$operator, $partner, $machine] as $identity) {
            $this->show($order, $identity->createToken('test', ['*', 'patient:*'])->plainTextToken)
                ->assertUnauthorized();
        }
    }

    public function test_patient_tokens_need_portal_abilities_and_legacy_wildcards_still_work(): void
    {
        $patient = Patient::factory()->create();
        $order = $this->ownedOrder($patient);
        foreach ([[], ['shopper:*'], ['frontend:*']] as $abilities) {
            $this->show($order, $patient->createToken('non-portal', $abilities)->plainTextToken)
                ->assertForbidden();
        }
        $this->show($order, $patient->createToken('legacy', ['*'])->plainTextToken)->assertOk();
    }

    public function test_customer_ownership_is_required_without_email_chart_or_legacy_fallback(): void
    {
        $patient = Patient::factory()->create(['prx_patient_chart_id' => 'chart-owner']);
        $token = $patient->createToken('portal', ['patient:*'])->plainTextToken;
        $owned = $this->ownedOrder($patient);
        $other = $this->ownedOrder(Patient::factory()->create());
        $other->customer->update(['email' => $patient->email, 'prx_patient_chart_id' => $patient->prx_patient_chart_id]);
        $other->update(['patient_id' => $patient->id]);
        $unowned = Order::factory()->create(['patient_id' => $patient->id]);
        $unknown = new Order(['uuid' => '00000000-0000-0000-0000-000000000000']);

        $this->show($owned, $token)->assertOk()->assertJsonPath('data.uuid', $owned->uuid);
        $expected = $this->show($unknown, $token)->assertNotFound()->json();
        foreach ([$other, $unowned] as $order) {
            $this->assertSame($expected, $this->show($order, $token)->assertNotFound()->json());
        }
        $this->assertNull($unowned->fresh()->customer_id);
    }

    public function test_conflicting_legacy_owner_is_denied_but_matching_legacy_owner_is_allowed(): void
    {
        $patient = Patient::factory()->create();
        $order = $this->ownedOrder($patient);
        $token = $patient->createToken('portal', ['patient:*'])->plainTextToken;
        $order->update(['patient_id' => Patient::factory()->create()->id]);
        $this->show($order, $token)->assertNotFound();
        $order->update(['patient_id' => $patient->id]);
        $this->show($order, $token)->assertOk();
    }

    public function test_deleted_customer_or_order_and_detached_portal_account_are_denied(): void
    {
        $patient = Patient::factory()->create();
        $order = $this->ownedOrder($patient);
        $customer = $order->customer;
        $token = $patient->createToken('portal', ['patient:*'])->plainTextToken;
        $customer->delete();
        $this->show($order, $token)->assertNotFound();
        $customer->restore();
        $customer->forceFill(['portal_account_id' => null])->save();
        $this->show($order, $token)->assertNotFound();
        $customer->forceFill(['portal_account_id' => $patient->id])->save();
        $order->delete();
        $this->show($order, $token)->assertNotFound();
    }

    public function test_expired_idle_and_revoked_portal_sessions_cannot_read_orders(): void
    {
        $patient = Patient::factory()->create();
        $order = $this->ownedOrder($patient);
        $expired = $patient->createToken('expired', ['patient:*'], now()->subMinute());
        $this->show($order, $expired->plainTextToken)->assertUnauthorized();
        $idle = $patient->createToken('idle', ['patient:*']);
        $idle->accessToken->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $this->show($order, $idle->plainTextToken)->assertUnauthorized();
        $revoked = $patient->createToken('revoked', ['patient:*']);
        $revoked->accessToken->delete();
        $this->show($order, $revoked->plainTextToken)->assertUnauthorized();
    }

    public function test_required_two_factor_policy_confines_the_existing_session(): void
    {
        $patient = Patient::factory()->create();
        $order = $this->ownedOrder($patient);
        $token = $patient->createToken('portal', ['patient:*'])->plainTextToken;
        $this->show($order, $token)->assertOk();
        $settings = app(PortalSettings::class);
        $settings->two_factor_policy = 'required';
        $settings->save();
        $this->show($order, $token)->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
    }
}
