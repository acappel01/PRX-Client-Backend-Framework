<?php

namespace Tests\Feature\Checkout;

use App\Actions\Checkout\SubmitPrescribeRxCheckoutAction;
use App\Actions\Customers\MapCustomerToProviderAction;
use App\Actions\Exceptions\ActionException;
use App\Data\PrescribeRx\UnifiedIntakeResponseData;
use App\Models\Catalog\Product;
use App\Models\Commerce\Cart;
use App\Models\Commerce\CheckoutAttempt;
use App\Models\Commerce\CheckoutReconciliationAudit;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\CustomerProviderLink;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\ProviderInstance;
use App\Models\Workflow\Workflow;
use App\Services\PrescribeRx\Client;
use App\Services\PrescribeRx\Exceptions\PrescribeRxException;
use App\Settings\IntegrationSettings;
use App\Workflows\Jobs\RunWorkflowChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckoutReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProviderInstance::create(['key' => 'checkout-test', 'provider' => 'prescribe_rx', 'environment' => 'sandbox', 'account_type' => 'sales_organization', 'external_account_id' => 'org-test']);
        app(IntegrationSettings::class)->fill(['prescribe_rx_provider_instance_key' => 'checkout-test', 'prescribe_rx_sales_org_id' => 'org-test', 'prescribe_rx_client_id' => null, 'prescribe_rx_environment' => 'sandbox']);
    }

    private function purchase(): array
    {
        $cart = Cart::factory()->create();
        $lead = Lead::factory()->create(['cart_ulid' => $cart->ulid]);
        $product = Product::factory()->create(['provider_product_id' => 'product-test']);
        $cart->items()->create(['itemable_type' => Product::class, 'itemable_id' => $product->id, 'quantity' => 1, 'unit_price_snapshot' => '19.99']);

        return [$cart, $lead];
    }

    private function recoverable(bool $claimed = false): CheckoutAttempt
    {
        [$cart, $lead] = $this->purchase();
        if ($claimed) {
            $account = Patient::factory()->withPrxChart()->create(['prx_patient_chart_id' => 'receipt-chart', 'email_verified_at' => now()]);
            $lead->forceFill(['patient_id' => $account->id])->save();
            Encounter::factory()->create(['lead_id' => $lead->id, 'prescribe_rx_patient_id' => 'receipt-chart']);
        }
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn(new UnifiedIntakeResponseData('receipt-encounter', 'ENC-1', 'receipt-chart', 'PAT-1'));
        $fail = true;
        Order::updating(function (Order $order) use (&$fail): void {
            if ($fail && $order->isDirty('encounter_id')) {
                throw new \RuntimeException('Simulated local failure');
            }
        });
        try {
            app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
            $this->fail('Expected initial failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated local failure', $exception->getMessage());
        }
        $fail = false;

        return CheckoutAttempt::sole();
    }

    private function commandOptions(CheckoutAttempt $attempt, bool $apply = false): array
    {
        return array_filter(['attempt-uuid' => $attempt->uuid, '--provider-instance' => 'checkout-test', '--apply' => $apply, '--reason' => $apply ? 'Local finalization failure reviewed' : null], fn ($value) => $value !== null && $value !== false);
    }

    public function test_preview_rolls_back_and_apply_is_local_idempotent_audited_and_preserves_receipt_time(): void
    {
        $attempt = $this->recoverable();
        $observed = $attempt->receipt_received_at;
        $this->travel(5)->days();
        app(IntegrationSettings::class)->fill(['prescribe_rx_environment' => 'production', 'prescribe_rx_provider_instance_key' => null]);
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt))->assertSuccessful();
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame(0, Encounter::count());
        $this->assertSame(0, CheckoutReconciliationAudit::count());
        $this->assertSame(1, Cart::sole()->items()->count());
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertSuccessful();
        $this->assertSame('completed', $attempt->fresh()->status);
        $this->assertSame('receipt-encounter', Encounter::sole()->prescribe_rx_encounter_id);
        $this->assertSame($observed->toDateTimeString(), Lead::sole()->handed_off_at->toDateTimeString());
        $audit = CheckoutReconciliationAudit::sole();
        $this->assertSame('unknown', $audit->before_status);
        $this->assertSame('completed', $audit->after_status);
        $this->assertSame('Local finalization failure reviewed', $audit->reason);
        $this->assertStringNotContainsString('Local finalization failure reviewed', $audit->getRawOriginal('reason'));
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertSuccessful();
        $this->assertSame(1, Encounter::count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, CheckoutReconciliationAudit::count());
        Http::assertNothingSent();
    }

    public function test_apply_requires_reason_and_matching_instance(): void
    {
        $attempt = $this->recoverable();
        $options = $this->commandOptions($attempt, true);
        unset($options['--reason']);
        $this->artisan('checkout:reconcile', $options)->assertFailed();
        $this->artisan('checkout:reconcile', [...$this->commandOptions($attempt, true), '--provider-instance' => 'wrong-instance'])->assertFailed();
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame(0, CheckoutReconciliationAudit::count());
    }

    public function test_unbound_historical_attempt_is_not_bound_from_current_settings(): void
    {
        $attempt = $this->recoverable();
        DB::table('checkout_attempts')->where('id', $attempt->id)->update(['provider_instance_id' => null]);
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertFailed();
        $this->assertNull($attempt->fresh()->provider_instance_id);
        $this->assertSame('unknown', $attempt->fresh()->status);
    }

    public function test_unknown_without_receipt_stays_unresolved_without_provider_retry(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andThrow(new PrescribeRxException('timeout'));
        try {
            app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        } catch (PrescribeRxException) {
        }
        $attempt = CheckoutAttempt::sole();
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertFailed();
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame(0, CheckoutReconciliationAudit::count());
    }

    public function test_order_snapshot_change_refuses_reconciliation_and_retains_receipt(): void
    {
        $attempt = $this->recoverable();
        Order::sole()->update(['currency' => 'EUR']);
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertFailed();
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame('receipt-chart', $attempt->fresh()->provider_receipt['patient_id']);
        $this->assertSame(0, Encounter::count());
    }

    public function test_unclaimed_lead_cannot_reconcile_an_order_with_a_legacy_account_owner(): void
    {
        $attempt = $this->recoverable();
        Order::sole()->update(['patient_id' => Patient::factory()->create()->id]);
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertFailed();
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame(0, Encounter::count());
    }

    public function test_cart_edits_survive_reconciliation(): void
    {
        $attempt = $this->recoverable();
        Cart::sole()->items()->update(['quantity' => 3]);
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertSuccessful();
        $this->assertSame(3, Cart::sole()->items()->sole()->quantity);
        $this->assertSame(1, Order::sole()->items()->sole()->quantity);
    }

    public function test_preview_and_apply_suppress_active_observer_workflows(): void
    {
        $attempt = $this->recoverable();
        Workflow::create(['name' => 'Lead update', 'slug' => 'lead-update', 'trigger_type' => 'model_updated', 'trigger_target' => 'lead', 'conditions' => [], 'is_active' => true]);
        Queue::fake();
        $control = Lead::factory()->create();
        $control->update(['status' => 'handed_off']);
        Queue::assertPushed(RunWorkflowChain::class);
        Queue::fake();
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt))->assertSuccessful();
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertSuccessful();
        Queue::assertNotPushed(RunWorkflowChain::class);
        Http::assertNothingSent();
    }

    public function test_missing_provider_namespace_fails_before_local_intent_or_provider_call(): void
    {
        [$cart, $lead] = $this->purchase();
        app(IntegrationSettings::class)->prescribe_rx_provider_instance_key = null;
        $this->mock(Client::class)->shouldNotReceive('submitUnifiedIntake');
        try {
            app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
            $this->fail('Expected binding failure');
        } catch (ActionException $exception) {
            $this->assertSame(503, $exception->getCode());
        }
        $this->assertSame(0, CheckoutAttempt::count());
        $this->assertSame(0, Order::count());
    }

    public function test_client_precedence_freezes_both_routing_ids_and_binding_is_immutable(): void
    {
        [$cart, $lead] = $this->purchase();
        $client = ProviderInstance::create(['key' => 'client-checkout', 'provider' => 'prescribe_rx', 'environment' => 'sandbox', 'account_type' => 'client', 'external_account_id' => 'client-test']);
        app(IntegrationSettings::class)->fill(['prescribe_rx_provider_instance_key' => $client->key, 'prescribe_rx_client_id' => 'client-test']);
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn(new UnifiedIntakeResponseData('client-encounter', 'ENC-1', 'client-chart', 'PAT-1'));
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        $attempt = CheckoutAttempt::sole();
        $this->assertSame($client->id, $attempt->provider_instance_id);
        $this->assertSame('client', $attempt->provider_tenant_kind);
        $this->assertSame('client-test', $attempt->provider_client_id);
        $this->assertSame('org-test', $attempt->provider_sales_org_id);
        $this->expectException(\LogicException::class);
        $attempt->update(['provider_client_id' => 'changed']);
    }

    public function test_mismatching_and_untyped_namespaces_cannot_create_local_intent(): void
    {
        $this->mock(Client::class)->shouldNotReceive('submitUnifiedIntake');
        ProviderInstance::create(['key' => 'legacy-tenant', 'provider' => 'prescribe_rx', 'environment' => 'sandbox', 'account_type' => 'tenant', 'external_account_id' => 'org-test']);
        ProviderInstance::create(['key' => 'wrong-provider', 'provider' => 'another_provider', 'environment' => 'sandbox', 'account_type' => 'sales_organization', 'external_account_id' => 'org-test']);
        $base = ['prescribe_rx_provider_instance_key' => 'checkout-test', 'prescribe_rx_environment' => 'sandbox', 'prescribe_rx_client_id' => null, 'prescribe_rx_sales_org_id' => 'org-test'];
        foreach ([
            ['prescribe_rx_environment' => 'production'],
            ['prescribe_rx_sales_org_id' => 'another-org'],
            ['prescribe_rx_sales_org_id' => null],
            ['prescribe_rx_client_id' => 'org-test'],
            ['prescribe_rx_provider_instance_key' => 'legacy-tenant'],
            ['prescribe_rx_provider_instance_key' => 'wrong-provider'],
        ] as $override) {
            app(IntegrationSettings::class)->fill([...$base, ...$override]);
            [$cart, $lead] = $this->purchase();
            try {
                app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
                $this->fail('Expected binding refusal');
            } catch (ActionException $exception) {
                $this->assertSame(503, $exception->getCode());
            }
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CheckoutAttempt::count());
    }

    public function test_verified_claim_reconciliation_reserves_chart_in_the_recorded_namespace_only(): void
    {
        $attempt = $this->recoverable(true);
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertSuccessful();
        $link = CustomerProviderLink::sole();
        $this->assertSame($attempt->provider_instance_id, $link->provider_instance_id);
        $this->assertSame(Order::sole()->customer_id, $link->customer_id);
        $this->assertSame('receipt-chart', $link->chart_id);
        $this->assertNull($link->patient_id);
    }

    public function test_chart_mapping_conflict_rolls_back_all_reconciliation_writes(): void
    {
        $attempt = $this->recoverable(true);
        $other = Customer::factory()->create();
        app(MapCustomerToProviderAction::class)->execute($other, ProviderInstance::findOrFail($attempt->provider_instance_id), 'receipt-chart');
        $beforeEncounters = Encounter::count();
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertFailed();
        $this->assertSame($beforeEncounters, Encounter::count());
        $this->assertNull(Order::sole()->encounter_id);
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame('receipt-chart', $attempt->fresh()->provider_receipt['patient_id']);
        $this->assertSame(0, CheckoutReconciliationAudit::count());
        $this->assertSame($other->id, CustomerProviderLink::sole()->customer_id);
    }

    public function test_reconciliation_audit_cannot_be_changed_or_deleted_through_model_writes(): void
    {
        $attempt = $this->recoverable();
        $this->artisan('checkout:reconcile', $this->commandOptions($attempt, true))->assertSuccessful();
        $audit = CheckoutReconciliationAudit::sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $audit->update(['reason' => 'changed']) : $audit->delete();
                $this->fail('Expected immutable audit refusal');
            } catch (\LogicException $exception) {
                $this->assertSame('Checkout reconciliation history is immutable.', $exception->getMessage());
            }
        }
        $this->assertSame('Local finalization failure reviewed', $audit->fresh()->reason);
    }
}
