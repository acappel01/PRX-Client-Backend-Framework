<?php

namespace Tests\Feature\Checkout;

use App\Actions\Checkout\SubmitPrescribeRxCheckoutAction;
use App\Actions\Exceptions\ActionException;
use App\Data\PrescribeRx\UnifiedIntakeResponseData;
use App\Enums\EncounterStatus;
use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Commerce\Cart;
use App\Models\Commerce\CheckoutAttempt;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Patient;
use App\Services\PrescribeRx\Client;
use App\Services\PrescribeRx\Exceptions\PrescribeRxException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalFirstPrescribeRxCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function purchase(): array
    {
        $cart = Cart::factory()->create();
        $lead = Lead::factory()->create(['cart_ulid' => $cart->ulid]);
        $product = Product::factory()->create(['provider_product_id' => 'product-test']);
        $cart->items()->create(['itemable_type' => Product::class, 'itemable_id' => $product->id, 'quantity' => 1, 'unit_price_snapshot' => '19.99']);

        return [$cart, $lead];
    }

    private function response(): UnifiedIntakeResponseData
    {
        return new UnifiedIntakeResponseData('encounter-test', 'ENC-1', 'chart-test', 'PAT-1');
    }

    public function test_order_and_snapshot_exist_before_network_and_completed_retry_replays_after_cart_clear(): void
    {
        [$cart, $lead] = $this->purchase();
        $level = DB::transactionLevel();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturnUsing(function ($request, $key) use ($level) {
            $this->assertSame($level, DB::transactionLevel());
            $order = Order::sole();
            $attempt = CheckoutAttempt::sole();
            $this->assertSame('19.99', $order->total_amount);
            $this->assertSame('19.99', $order->items()->sole()->line_total);
            $this->assertSame('submitting', $attempt->status);
            $this->assertSame($attempt->uuid, $request->metadata['checkout_context_uuid']);
            $this->assertSame($attempt->provider_idempotency_key, $key);

            return $this->response();
        });
        $action = app(SubmitPrescribeRxCheckoutAction::class);
        $first = $action->execute($cart, $lead, ['test_answer' => 'not stored']);
        $again = $action->execute($cart, $lead, ['test_answer' => 'not stored']);
        $this->assertSame($first->toArray(), $again->toArray());
        $this->assertSame(0, $cart->items()->count());
        $this->assertSame($lead->id, Encounter::sole()->lead_id);
        $this->assertSame(1, Order::count());
        $this->assertStringNotContainsString('not stored', json_encode(DB::table('checkout_attempts')->first()));
        $this->assertStringNotContainsString('chart-test', DB::table('checkout_attempts')->value('result'));
    }

    public function test_unknown_outcome_preserves_purchase_and_never_retries_even_after_provider_ttl(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andThrow(new PrescribeRxException('timeout'));
        $action = app(SubmitPrescribeRxCheckoutAction::class);
        try {
            $action->execute($cart, $lead);
            $this->fail('Expected failure');
        } catch (PrescribeRxException) {
        }
        $this->assertSame('unknown', CheckoutAttempt::sole()->status);
        $this->assertSame('19.99', Order::sole()->total_amount);
        $this->assertSame(1, $cart->items()->count());
        $this->travel(48)->hours();
        $this->expectException(ActionException::class);
        $this->expectExceptionCode(409);
        $action->execute($cart, $lead);
    }

    public function test_in_progress_submission_cannot_call_provider_again(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturnUsing(function () use ($cart, $lead) {
            try {
                app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
                $this->fail('Concurrent submission must be refused');
            } catch (ActionException $exception) {
                $this->assertSame(409, $exception->getCode());
            }

            return $this->response();
        });
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        $this->assertSame(1, Order::count());
    }

    public function test_edits_during_network_are_preserved_and_cannot_replay_old_selection(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturnUsing(function () use ($cart) {
            $cart->items()->update(['quantity' => 2]);

            return $this->response();
        });
        $action = app(SubmitPrescribeRxCheckoutAction::class);
        $action->execute($cart, $lead);
        $this->assertSame(2, $cart->items()->sole()->quantity);
        $this->assertSame(1, Order::sole()->items()->sole()->quantity);
        $this->expectException(ActionException::class);
        $this->expectExceptionCode(409);
        $action->execute($cart, $lead);
    }

    public function test_changed_answers_cannot_replay_a_completed_order(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn($this->response());
        $action = app(SubmitPrescribeRxCheckoutAction::class);
        $action->execute($cart, $lead, ['answer' => 'one']);
        $this->expectException(ActionException::class);
        $this->expectExceptionCode(409);
        $action->execute($cart, $lead, ['answer' => 'two']);
    }

    public function test_partially_unmapped_cart_is_rejected_before_any_purchase_or_provider_call(): void
    {
        [$cart, $lead] = $this->purchase();
        $product = Product::factory()->create(['provider_product_id' => null, 'provider_product_sku' => null]);
        $cart->items()->create(['itemable_type' => Product::class, 'itemable_id' => $product->id, 'quantity' => 1, 'unit_price_snapshot' => '10.00']);
        $this->mock(Client::class)->shouldNotReceive('submitUnifiedIntake');
        try {
            app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
            $this->fail('Expected rejection');
        } catch (ActionException $exception) {
            $this->assertSame(503, $exception->getCode());
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CheckoutAttempt::count());
    }

    public function test_unbound_lead_cannot_submit(): void
    {
        [$cart, $lead] = $this->purchase();
        $lead->update(['cart_ulid' => null]);
        $this->mock(Client::class)->shouldNotReceive('submitUnifiedIntake');
        $this->expectException(ActionException::class);
        $this->expectExceptionCode(403);
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
    }

    public function test_a_different_lead_cannot_bypass_an_uncertain_cart_attempt(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andThrow(new PrescribeRxException('timeout'));
        $action = app(SubmitPrescribeRxCheckoutAction::class);
        try {
            $action->execute($cart, $lead);
        } catch (PrescribeRxException) {
        }
        $newLead = Lead::factory()->create(['cart_ulid' => $cart->ulid]);
        $this->expectException(ActionException::class);
        $this->expectExceptionCode(409);
        $action->execute($cart, $newLead);
    }

    public function test_attempt_references_are_hidden_and_identity_cannot_be_changed(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn($this->response());
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        $attempt = CheckoutAttempt::sole();
        foreach (['result', 'provider_receipt', 'lead_id', 'cart_id', 'order_id', 'request_fingerprint', 'provider_idempotency_key'] as $key) {
            $this->assertArrayNotHasKey($key, $attempt->toArray());
        }
        $this->expectException(\LogicException::class);
        $attempt->update(['provider_idempotency_key' => 'changed']);
    }

    public function test_attempt_cart_is_not_prunable_if_lead_binding_is_later_removed(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn($this->response());
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        $lead->update(['cart_ulid' => null]);
        $cart->forceFill(['updated_at' => now()->subDays(100)])->save();
        $this->assertFalse((new Cart)->prunable()->whereKey($cart->id)->exists());
    }

    public function test_existing_order_encounter_cannot_be_overwritten_during_finalization(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturnUsing(function () use ($lead) {
            $encounter = Encounter::create(['prescribe_rx_encounter_id' => 'already-linked', 'lead_id' => $lead->id, 'status' => EncounterStatus::Submitted]);
            Order::sole()->update(['encounter_id' => $encounter->id]);

            return $this->response();
        });
        try {
            app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
            $this->fail('Expected conflict');
        } catch (ActionException $exception) {
            $this->assertSame(409, $exception->getCode());
        }
        $this->assertSame('unknown', CheckoutAttempt::sole()->status);
        $this->assertSame(1, Encounter::count());
        $this->assertSame('already-linked', Encounter::sole()->prescribe_rx_encounter_id);
    }

    public function test_verified_claim_assigns_customer_before_network_and_after_canonical_response(): void
    {
        [$cart, $lead] = $this->purchase();
        $account = Patient::factory()->withPrxChart()->create([
            'prx_patient_chart_id' => 'chart-test', 'email_verified_at' => now(),
        ]);
        $lead->forceFill(['patient_id' => $account->id])->save();
        Encounter::factory()->create(['lead_id' => $lead->id, 'prescribe_rx_patient_id' => 'chart-test']);
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturnUsing(function () use ($account, $lead) {
            $customer = Customer::where('portal_account_id', $account->id)->sole();
            $this->assertSame($customer->id, $lead->fresh()->customer_id);
            $this->assertSame($customer->id, Order::sole()->customer_id);

            return $this->response();
        });
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        $this->assertSame($lead->fresh()->customer_id, Order::sole()->customer_id);
        $this->assertSame('completed', CheckoutAttempt::sole()->status);
    }

    public function test_provider_receipt_survives_finalization_rollback_and_does_not_allow_resubmission(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn($this->response());
        Order::updating(function (Order $order): void {
            if ($order->isDirty('encounter_id')) {
                throw new \RuntimeException('Simulated finalization failure');
            }
        });
        $action = app(SubmitPrescribeRxCheckoutAction::class);
        try {
            $action->execute($cart, $lead);
            $this->fail('Expected finalization failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated finalization failure', $exception->getMessage());
        }
        $attempt = CheckoutAttempt::sole();
        $this->assertSame('unknown', $attempt->status);
        $this->assertSame([
            'encounter_id' => 'encounter-test', 'encounter_number' => 'ENC-1',
            'patient_id' => 'chart-test', 'status' => 'pending_intake',
        ], $attempt->provider_receipt);
        $this->assertStringNotContainsString('chart-test', DB::table('checkout_attempts')->value('provider_receipt'));
        $this->assertSame(OrderStatus::Pending, Order::sole()->status);
        $this->assertNull(Order::sole()->encounter_id);
        $this->assertNull($attempt->result);
        $this->assertSame(0, Encounter::count());
        $this->assertSame(1, $cart->items()->count());
        $this->expectException(ActionException::class);
        $this->expectExceptionCode(409);
        $action->execute($cart, $lead);
    }

    public function test_blank_canonical_reference_is_not_finalized_or_saved_as_a_receipt(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn(
            new UnifiedIntakeResponseData('  ', 'ENC-1', 'chart-test', 'PAT-1')
        );
        try {
            app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
            $this->fail('Expected rejection');
        } catch (ActionException $exception) {
            $this->assertSame(502, $exception->getCode());
        }
        $this->assertSame('unknown', CheckoutAttempt::sole()->status);
        $this->assertNull(CheckoutAttempt::sole()->provider_receipt);
        $this->assertNull(Order::sole()->encounter_id);
        $this->assertSame(0, Encounter::count());
    }

    public function test_completed_cart_rotates_once_and_supports_a_second_purchase_with_old_token_replay(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->twice()->andReturn(
            $this->response(), new UnifiedIntakeResponseData('encounter-second', 'ENC-2', 'chart-test', 'PAT-1')
        );
        $action = app(SubmitPrescribeRxCheckoutAction::class);
        $first = $action->execute($cart, $lead);
        $product = Product::factory()->create(['provider_product_id' => 'product-second', 'retail_price' => 29]);
        $added = $this->postJson('/api/v1/cart/items', ['type' => 'product', 'id' => $product->id], ['X-Cart-Token' => $cart->ulid])->assertCreated();
        $token = $added->json('data.token');
        $this->assertNotSame($cart->ulid, $token);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $cart->ulid])->assertOk()->assertJsonPath('data.token', $token)->assertJsonPath('data.item_count', 1);
        $this->assertSame(2, Cart::count());
        $successor = Cart::where('ulid', $token)->sole();
        $nextLead = Lead::factory()->create(['cart_ulid' => $token]);
        $second = $action->execute($successor, $nextLead);
        $this->assertNotSame($first->order_uuid, $second->order_uuid);
        $this->assertSame(2, CheckoutAttempt::count());
        $this->assertSame($first->toArray(), $action->execute($cart, $lead)->toArray());
        // Following the oldest token after another completed purchase resolves
        // to one shared third cart, rather than creating disconnected successors.
        $third = $this->getJson('/api/v1/cart', ['X-Cart-Token' => $cart->ulid])->assertOk()->json('data.token');
        $this->assertNotSame($token, $third);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertOk()->assertJsonPath('data.token', $third);
        $this->assertSame(3, Cart::count());
        $thirdCart = Cart::where('ulid', $third)->sole();
        $thirdCart->forceFill(['updated_at' => now()->subDays(100)])->save();
        $this->assertFalse((new Cart)->prunable()->whereKey($thirdCart->id)->exists());
    }

    public function test_rotation_preserves_during_call_edits_and_route_bound_item_updates(): void
    {
        [$cart, $lead] = $this->purchase();
        $itemId = $cart->items()->sole()->id;
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturnUsing(function () use ($cart, $itemId) {
            $this->patchJson('/api/v1/cart/items/'.$itemId, ['quantity' => 2], ['X-Cart-Token' => $cart->ulid])
                ->assertOk()->assertJsonPath('data.token', $cart->ulid);

            return $this->response();
        });
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        // Route binding happens before resolution moves retained cart rows.
        $updated = $this->patchJson('/api/v1/cart/items/'.$itemId, ['quantity' => 3], ['X-Cart-Token' => $cart->ulid])->assertOk();
        $this->assertNotSame($cart->ulid, $updated->json('data.token'));
        $this->assertSame(3, $updated->json('data.item_count'));
        $this->assertSame(1, Order::sole()->items()->sole()->quantity);
        $this->assertSame(0, $cart->items()->count());
    }

    public function test_unknown_cart_never_rotates_even_when_expired(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andThrow(new PrescribeRxException('timeout'));
        try {
            app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        } catch (PrescribeRxException) {
        }
        $cart->update(['expires_at' => now()->subDay()]);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $cart->ulid])->assertStatus(409);
        $this->assertSame(1, Cart::count());
        $this->assertNull($cart->fresh()->successor_cart_id);
    }

    public function test_expired_predecessor_token_cannot_access_a_live_successor(): void
    {
        [$cart, $lead] = $this->purchase();
        $this->mock(Client::class)->shouldReceive('submitUnifiedIntake')->once()->andReturn($this->response());
        app(SubmitPrescribeRxCheckoutAction::class)->execute($cart, $lead);
        $successorToken = $this->getJson('/api/v1/cart', ['X-Cart-Token' => $cart->ulid])->assertOk()->json('data.token');
        $product = Product::factory()->create(['retail_price' => 39]);
        $this->postJson('/api/v1/cart/items', ['type' => 'product', 'id' => $product->id], ['X-Cart-Token' => $successorToken])->assertCreated();
        $cart->update(['expires_at' => now()->subSecond()]);
        $expired = $this->getJson('/api/v1/cart', ['X-Cart-Token' => $cart->ulid])->assertOk()->assertJsonPath('data.item_count', 0);
        $this->assertNotSame($successorToken, $expired->json('data.token'));
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $successorToken])->assertOk()->assertJsonPath('data.item_count', 1);
    }
}
