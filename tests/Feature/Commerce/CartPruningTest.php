<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\Cart;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every anonymous visitor mints a cart row and nothing else removes it, so the
 * table grows with traffic. These pin the two guards that make reaping safe.
 */
class CartPruningTest extends TestCase
{
    use RefreshDatabase;

    private function cart(string $updatedAt): Cart
    {
        $cart = Cart::factory()->create();
        // Written directly: touching the model would refresh updated_at, which
        // is the column under test.
        Cart::withoutTimestamps(fn () => $cart->forceFill(['updated_at' => $updatedAt])->save());

        return $cart->fresh();
    }

    public function test_a_cart_untouched_for_more_than_90_days_is_prunable(): void
    {
        $this->cart(now()->subDays(91)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [Cart::class]])->assertOk();

        $this->assertSame(0, Cart::count());
    }

    public function test_a_recently_touched_cart_survives(): void
    {
        $this->cart(now()->subDays(89)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [Cart::class]])->assertOk();

        $this->assertSame(1, Cart::count());
    }

    /**
     * THE GUARD THAT MATTERS. CheckoutController resolves the cart by ulid with
     * firstOrFail() and hash_equals() it against leads.cart_ulid; reaping one of
     * these 404s that checkout. The reference is also held remotely by the
     * telehealth provider, so it outlives anything on this box.
     */
    public function test_a_cart_referenced_by_a_lead_is_never_pruned(): void
    {
        $cart = $this->cart(now()->subDays(400)->toDateTimeString());
        Lead::factory()->create(['cart_ulid' => $cart->ulid]);

        $this->artisan('model:prune', ['--model' => [Cart::class]])->assertOk();

        $this->assertSame(1, Cart::count());
        $this->assertNotNull(Cart::where('ulid', $cart->ulid)->first());
    }
}
