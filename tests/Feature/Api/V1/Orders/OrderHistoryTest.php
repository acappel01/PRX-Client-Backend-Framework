<?php

namespace Tests\Feature\Api\V1\Orders;

use App\Models\ApiClient;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderItem;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function history(?string $token = null, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/v1/orders'.($query === [] ? '' : '?'.http_build_query($query)),
            $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    private function owner(): array
    {
        $patient = Patient::factory()->create();
        $customer = Customer::factory()->create(['portal_account_id' => $patient->id]);

        return [$patient, $customer, $patient->createToken('portal', ['patient:*'])->plainTextToken];
    }

    public function test_pagination_and_detail_share_the_same_ownership_boundary(): void
    {
        [$patient, $customer, $token] = $this->owner();
        $otherAccount = Patient::factory()->create();
        $otherCustomer = Customer::factory()->create(['portal_account_id' => $otherAccount->id, 'email' => $patient->email]);
        $allowed = Order::factory()->create(['customer_id' => $customer->id]);
        $matchingLegacy = Order::factory()->create(['customer_id' => $customer->id, 'patient_id' => $patient->id]);
        $denied = [
            Order::factory()->create(['customer_id' => $otherCustomer->id, 'patient_id' => $patient->id]),
            Order::factory()->create(['customer_id' => $customer->id, 'patient_id' => $otherAccount->id]),
            Order::factory()->create(['patient_id' => $patient->id]),
            Order::factory()->create(['customer_id' => $customer->id]),
        ];
        $denied[3]->delete();

        $response = $this->history($token, ['customer_id' => $otherCustomer->id, 'patient_id' => $otherAccount->id,
            'with_trashed' => 1, 'include' => 'customer,encounter', 'sort' => 'id']);
        $response->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$allowed->uuid, $matchingLegacy->uuid], array_column($response->json('data'), 'uuid'));
        foreach ($denied as $order) {
            $this->assertStringNotContainsString($order->uuid, $response->getContent());
            $this->getJson('/api/v1/orders/'.$order->uuid, ['Authorization' => 'Bearer '.$token])->assertNotFound();
        }
        foreach ([$allowed, $matchingLegacy] as $order) {
            $this->getJson('/api/v1/orders/'.$order->uuid, ['Authorization' => 'Bearer '.$token])->assertOk();
        }
        $this->assertStringNotContainsString('with_trashed', json_encode($response->json('links')));
        $this->assertNull($denied[2]->fresh()->customer_id);
    }

    public function test_deleted_or_detached_customer_and_unprovisioned_account_return_empty_history(): void
    {
        [$patient, $customer, $token] = $this->owner();
        Order::factory()->create(['customer_id' => $customer->id]);
        $customer->delete();
        $this->history($token)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $customer->restore();
        $customer->forceFill(['portal_account_id' => null])->save();
        $this->history($token)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $unprovisioned = Patient::factory()->create();
        $this->history($unprovisioned->createToken('portal', ['patient:*'])->plainTextToken)
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('customers', 1);
        Http::assertNothingSent();
    }

    public function test_summary_has_only_explicit_commerce_fields_and_no_contact_address_or_provider_identity(): void
    {
        [, $customer, $token] = $this->owner();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'total_amount' => '149.50',
            'shipping_address' => ['street' => 'Private street'], 'billing_address' => ['street' => 'Private billing'],
            'metadata' => ['email' => 'private@example.test', 'patient_chart_id' => 'private-chart'],
            'prescribe_rx_order_id' => 'private-provider-order']);
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Private treatment']);
        $response = $this->history($token)->assertOk()->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.0.total_amount', '149.50')->assertJsonPath('data.0.currency', 'USD');
        $this->assertSame(['uuid', 'status', 'total_amount', 'currency', 'placed_at', 'shipped_at',
            'delivered_at', 'cancelled_at', 'items_count'], array_keys($response->json('data.0')));
        foreach (['Private street', 'Private billing', 'private@example.test', 'private-chart', 'private-provider-order', 'Private treatment'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_pages_have_stable_timestamp_tie_order_and_only_owned_counts(): void
    {
        [, $customer, $token] = $this->owner();
        $older = Order::factory()->create(['customer_id' => $customer->id, 'placed_at' => '2026-08-01 10:00:00']);
        $tieFirst = Order::factory()->create(['customer_id' => $customer->id, 'placed_at' => '2026-09-01 10:00:00']);
        $tieLast = Order::factory()->create(['customer_id' => $customer->id, 'placed_at' => '2026-09-01 10:00:00']);
        $undated = Order::factory()->create(['customer_id' => $customer->id, 'placed_at' => null]);
        Order::factory()->count(3)->create();
        $first = $this->history($token, ['per_page' => 2])->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.current_page', 1);
        $second = $this->history($token, ['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertSame([$tieLast->uuid, $tieFirst->uuid], array_column($first->json('data'), 'uuid'));
        $this->assertSame([$older->uuid, $undated->uuid], array_column($second->json('data'), 'uuid'));
        $this->assertStringContainsString('per_page=2', $first->json('links.next'));
        $this->assertStringContainsString('page=2', $first->json('links.next'));
        $this->history($token, ['page' => 3, 'per_page' => 2])->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 4);
        $this->history($token)->assertOk()->assertJsonPath('meta.per_page', 20);
    }

    public function test_invalid_pagination_is_rejected_without_cacheable_errors(): void
    {
        [, , $token] = $this->owner();
        foreach ([['page' => 0], ['page' => -1], ['page' => 10001], ['page' => 'text'], ['page' => [1]],
            ['per_page' => 0], ['per_page' => -1], ['per_page' => 101], ['per_page' => '1.5'], ['per_page' => [1]]] as $query) {
            $this->history($token, $query)->assertUnprocessable()->assertJsonValidationErrors(array_key_first($query));
        }
        $this->history($token, ['per_page' => 100, 'page' => 10000])->assertOk()->assertJsonPath('data', []);
    }

    public function test_history_denies_anonymous_operator_partner_and_machine_identities(): void
    {
        $this->history()->assertUnauthorized();
        $operator = User::factory()->create();
        $partner = User::factory()->create();
        $partner->assignRole(Role::findOrCreate('partner', 'web'));
        $machine = ApiClient::factory()->create();
        foreach ([$operator, $partner, $machine] as $identity) {
            $this->history($identity->createToken('not-portal', ['*', 'patient:*'])->plainTextToken)->assertUnauthorized();
        }
    }

    public function test_portal_ability_two_factor_and_session_expiry_protect_history(): void
    {
        [$patient, , $token] = $this->owner();
        foreach ([[], ['shopper:*'], ['frontend:*']] as $abilities) {
            $this->history($patient->createToken('not-portal', $abilities)->plainTextToken)->assertForbidden();
        }
        $this->history($patient->createToken('legacy', ['*'])->plainTextToken)->assertOk();
        $expired = $patient->createToken('expired', ['patient:*'], now()->subMinute());
        $this->history($expired->plainTextToken)->assertUnauthorized();
        $idle = $patient->createToken('idle', ['patient:*']);
        $idle->accessToken->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $this->history($idle->plainTextToken)->assertUnauthorized();
        $settings = app(PortalSettings::class);
        $settings->two_factor_policy = 'required';
        $settings->save();
        $this->history($token)->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
    }
}
