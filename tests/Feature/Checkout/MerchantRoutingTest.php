<?php

namespace Tests\Feature\Checkout;

use App\Actions\Payments\RecordMerchantUsageAction;
use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Models\Payments\MerchantAccount;
use App\Services\Payments\MerchantRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gateways rotate as they approach their processing limits, without anyone
 * editing a setting, and the storefront follows on its next request.
 */
class MerchantRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $attributes = []): MerchantAccount
    {
        return MerchantAccount::factory()->create(array_merge([
            'gateway_provider' => GatewayProvider::AuthorizeNet,
            'environment' => GatewayEnvironment::Sandbox,
            'authnet_api_login_id' => 'login',
            'authnet_public_client_key' => 'client',
            'is_active' => true,
            'supports_public_checkout' => true,
            'monthly_volume_limit' => null,
            'monthly_volume_used' => 0,
            'transaction_weight' => 1,
        ], $attributes));
    }

    private function router(): MerchantRoutingService
    {
        return app(MerchantRoutingService::class);
    }

    /**
     * A charge that would BREACH the limit skips the account rather than being
     * squeezed in — the account is otherwise healthy, so only the amount
     * disqualifies it.
     */
    public function test_a_charge_larger_than_the_remaining_headroom_skips_that_account(): void
    {
        $nearlyFull = $this->account(['name' => 'Nearly full', 'monthly_volume_limit' => 1000, 'monthly_volume_used' => 800]);
        $roomy = $this->account(['name' => 'Roomy', 'monthly_volume_limit' => 10000, 'monthly_volume_used' => 0]);

        $eligible = $this->router()->eligible(900.0)->pluck('id');

        $this->assertFalse($eligible->contains($nearlyFull->id), 'A $900 charge does not fit in $200 of headroom.');
        $this->assertTrue($eligible->contains($roomy->id));
    }

    /** With headroom to spare, the same account is eligible again. */
    public function test_a_charge_within_the_headroom_keeps_the_account_eligible(): void
    {
        $account = $this->account(['monthly_volume_limit' => 1000, 'monthly_volume_used' => 800]);

        $this->assertTrue($this->router()->eligible(150.0)->pluck('id')->contains($account->id));
    }

    /**
     * Weight falls off as an account fills rather than cutting off. A hard
     * cliff would send everything to one account until the instant it died.
     */
    public function test_weight_decays_with_usage_but_never_reaches_zero(): void
    {
        $empty = $this->account(['monthly_volume_limit' => 1000, 'monthly_volume_used' => 0, 'transaction_weight' => 10]);
        $half = $this->account(['monthly_volume_limit' => 1000, 'monthly_volume_used' => 500, 'transaction_weight' => 10]);
        $brimming = $this->account(['monthly_volume_limit' => 1000, 'monthly_volume_used' => 999, 'transaction_weight' => 10]);

        $router = $this->router();

        $this->assertSame(10.0, $router->effectiveWeight($empty));
        $this->assertEqualsWithDelta(5.0, $router->effectiveWeight($half), 0.01);
        $this->assertGreaterThan(0.0, $router->effectiveWeight($brimming), 'A nearly-full account must still take the occasional charge.');
        $this->assertLessThan($router->effectiveWeight($half), $router->effectiveWeight($brimming));
    }

    /** An account with no limit has no ratio to decay against. */
    public function test_an_unlimited_account_keeps_its_full_weight(): void
    {
        $account = $this->account(['monthly_volume_limit' => null, 'transaction_weight' => 7]);

        $this->assertSame(7.0, $this->router()->effectiveWeight($account));
        $this->assertNull($this->router()->usageRatio($account));
    }

    public function test_an_inactive_or_private_account_is_never_selected(): void
    {
        $this->account(['is_active' => false]);
        $this->account(['supports_public_checkout' => false]);

        $this->assertNull($this->router()->select(100.0));
    }

    /**
     * Recording usage is what actually retires an account. Without the
     * auto-disable the limit is a number nobody acts on.
     */
    public function test_reaching_the_limit_disables_the_account(): void
    {
        $account = $this->account([
            'monthly_volume_limit' => 500,
            'monthly_volume_used' => 400,
            'auto_disable_at_limit' => true,
        ]);

        $updated = app(RecordMerchantUsageAction::class)->execute($account, 100.0);

        $this->assertSame(500.0, (float) $updated->monthly_volume_used);
        $this->assertFalse((bool) $updated->is_active);
        $this->assertNotNull($updated->auto_disabled_at);

        // And the storefront follows immediately, with no setting edited.
        $this->assertNull($this->router()->select(10.0));
    }

    /** Without the flag, the limit still gates routing but the account stays up. */
    public function test_an_account_without_auto_disable_stays_active_at_its_limit(): void
    {
        $account = $this->account([
            'monthly_volume_limit' => 500,
            'monthly_volume_used' => 400,
            'auto_disable_at_limit' => false,
        ]);

        $updated = app(RecordMerchantUsageAction::class)->execute($account, 100.0);

        $this->assertTrue((bool) $updated->is_active);
        $this->assertNull($updated->auto_disabled_at);
    }

    /**
     * THE ACCOUNT THAT TOKENISES MUST BE THE ACCOUNT THAT CHARGES — an opaque
     * token is worthless at another gateway — so the context names it.
     */
    public function test_the_gateway_context_names_the_account_it_belongs_to(): void
    {
        $account = $this->account(['is_default' => true]);

        $this->getJson('/api/v1/checkout/gateway-config')
            ->assertOk()
            ->assertJsonPath('data.merchant_account_id', $account->uuid);
    }

    /** Every account over its limit means no payment, loudly. */
    public function test_no_eligible_account_is_a_503_rather_than_a_wrong_gateway(): void
    {
        $this->account(['monthly_volume_limit' => 100, 'monthly_volume_used' => 100, 'is_active' => false]);

        $this->getJson('/api/v1/checkout/gateway-config')->assertStatus(503);
    }
}
