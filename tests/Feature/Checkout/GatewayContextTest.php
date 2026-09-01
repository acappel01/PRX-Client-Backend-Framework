<?php

namespace Tests\Feature\Checkout;

use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Models\Payments\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /checkout/gateway-config` is consumed by the BROWSER, so this is a
 * security boundary before it is a feature.
 *
 * The card number must never reach our servers: the browser loads the
 * gateway's own SDK, hands it the PAN, and returns an opaque token. That holds
 * only while this endpoint emits publishable credentials and nothing else.
 */
class GatewayContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every secret column on the model, checked by VALUE against the whole
     * serialized response — so a future key added to the payload under any
     * name still fails here rather than shipping to the browser.
     */
    public function test_no_secret_credential_can_reach_the_browser(): void
    {
        $secrets = [
            'nmi_security_key' => 'SECRET-nmi-security',
            'authnet_transaction_key' => 'SECRET-authnet-transaction',
            'authnet_signature_key' => 'SECRET-authnet-signature',
            'stripe_secret_key' => 'SECRET-stripe-secret',
            'stripe_webhook_secret' => 'SECRET-stripe-webhook',
            'square_access_token' => 'SECRET-square-access',
            'square_webhook_signature_key' => 'SECRET-square-webhook',
        ];

        MerchantAccount::factory()->create(array_merge($secrets, [
            'gateway_provider' => GatewayProvider::AuthorizeNet,
            'environment' => GatewayEnvironment::Sandbox,
            'authnet_api_login_id' => 'login-public',
            'authnet_public_client_key' => 'client-public',
            'is_default' => true,
            'is_active' => true,
        ]));

        $body = $this->getJson('/api/v1/checkout/gateway-config')->assertOk()->content();

        foreach ($secrets as $column => $value) {
            $this->assertStringNotContainsString($value, $body, "{$column} reached the browser.");
        }
    }

    /**
     * Authorize.Net's `Accept.dispatchData` needs a PAIR — apiLoginID AND
     * clientKey. A single flattened `public_key` cannot tokenise a card, which
     * is why the context is per-provider.
     */
    public function test_authorize_net_carries_both_halves_and_its_environment_sdk_url(): void
    {
        MerchantAccount::factory()->create([
            'gateway_provider' => GatewayProvider::AuthorizeNet,
            'environment' => GatewayEnvironment::Sandbox,
            'authnet_api_login_id' => 'login-public',
            'authnet_public_client_key' => 'client-public',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/checkout/gateway-config')
            ->assertOk()
            ->assertJsonPath('data.gateway_provider', 'authorize_net')
            ->assertJsonPath('data.api_login_id', 'login-public')
            ->assertJsonPath('data.public_key', 'client-public')
            ->assertJsonPath('data.accept_js_url', 'https://jstest.authorize.net/v1/Accept.js');
    }

    /** The wrong Accept.js host fails at tokenise-time with an opaque error. */
    public function test_production_authorize_net_gets_the_production_sdk_url(): void
    {
        MerchantAccount::factory()->create([
            'gateway_provider' => GatewayProvider::AuthorizeNet,
            'environment' => GatewayEnvironment::Production,
            'authnet_api_login_id' => 'login-public',
            'authnet_public_client_key' => 'client-public',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/checkout/gateway-config')
            ->assertOk()
            ->assertJsonPath('data.accept_js_url', 'https://js.authorize.net/v1/Accept.js');
    }

    public function test_square_carries_its_location_id(): void
    {
        MerchantAccount::factory()->create([
            'gateway_provider' => GatewayProvider::Square,
            'environment' => GatewayEnvironment::Sandbox,
            'square_application_id' => 'sq-app-public',
            'square_location_id' => 'LOC-123',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/checkout/gateway-config')
            ->assertOk()
            ->assertJsonPath('data.public_key', 'sq-app-public')
            ->assertJsonPath('data.location_id', 'LOC-123');
    }

    /**
     * A half-configured gateway is WORSE than none: the storefront would
     * render a card form that fails at submit, after the visitor has typed
     * their number. Refuse up front instead.
     */
    public function test_a_gateway_missing_half_its_credentials_is_refused(): void
    {
        MerchantAccount::factory()->create([
            'gateway_provider' => GatewayProvider::AuthorizeNet,
            'environment' => GatewayEnvironment::Sandbox,
            'authnet_api_login_id' => null,
            'authnet_public_client_key' => 'client-public',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/checkout/gateway-config')->assertStatus(503);
    }

    /**
     * Both storefront sequences need the provider to accept OUR merchant
     * account as theirs — they validate it when the intake finalises, and
     * without it a card we vaulted cannot be captured or rebilled on their
     * side. `fillable` is the only guard on this column, so the round trip is
     * asserted rather than assumed.
     */
    public function test_the_provider_merchant_profile_id_persists(): void
    {
        $account = MerchantAccount::factory()->create([
            'gateway_provider' => GatewayProvider::AuthorizeNet,
            'provider_merchant_profile_id' => 'mp_01a05c33',
        ]);

        $this->assertSame('mp_01a05c33', $account->fresh()->provider_merchant_profile_id);
    }

    /**
     * It identifies an account to the PROVIDER, server to server. The browser
     * has no use for it, and everything in this payload is public by
     * construction — so it stays out.
     */
    public function test_the_provider_profile_id_is_not_sent_to_the_browser(): void
    {
        MerchantAccount::factory()->create([
            'gateway_provider' => GatewayProvider::AuthorizeNet,
            'environment' => GatewayEnvironment::Sandbox,
            'authnet_api_login_id' => 'login-public',
            'authnet_public_client_key' => 'client-public',
            'provider_merchant_profile_id' => 'mp_secretish',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->assertStringNotContainsString(
            'mp_secretish',
            $this->getJson('/api/v1/checkout/gateway-config')->assertOk()->content()
        );
    }

    public function test_no_configured_gateway_is_a_503(): void
    {
        $this->getJson('/api/v1/checkout/gateway-config')->assertStatus(503);
    }
}
