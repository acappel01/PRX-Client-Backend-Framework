<?php

namespace App\Data\Checkout;

use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Models\Payments\MerchantAccount;
use Spatie\LaravelData\Data;

/**
 * Everything the STOREFRONT needs to render a card form and tokenise a card —
 * and deliberately nothing more.
 *
 * THE WHOLE POINT IS THAT A CARD NUMBER NEVER REACHES OUR SERVERS. The browser
 * loads the gateway's own SDK, hands it the PAN directly, and receives an
 * opaque token; we only ever see that token. So this payload carries public,
 * publishable credentials only. Adding a secret here — `authnet_transaction_key`,
 * `nmi_security_key`, `stripe_secret_key`, `square_access_token` — would put it
 * in the browser bundle of every visitor and undo that separation. There is a
 * test asserting no secret column ever appears in this output.
 *
 * WHY IT IS PER-PROVIDER RATHER THAN ONE `public_key`. Authorize.Net's
 * `Accept.dispatchData` requires a PAIR — `apiLoginID` AND `clientKey` — so a
 * single flattened key cannot tokenise a card at all. Each gateway also needs
 * different extras: Square needs a location id, Authorize.Net needs an
 * environment-specific Accept.js URL. Mirrors the shape prx-demo has proven in
 * production.
 */
class GatewayContextData extends Data
{
    public function __construct(
        /**
         * WHICH ACCOUNT THIS CONTEXT BELONGS TO, and it must travel back with
         * the token. The browser mints an opaque token against ONE gateway's
         * SDK and that token is worthless at any other — so if routing moves
         * to a different account while the visitor is typing, charging the
         * newly-selected one would fail. The storefront hands this back at
         * submit and the charge uses it.
         */
        public string $merchant_account_id,
        public string $gateway_provider,
        public string $environment,

        /** Publishable / client key. Stripe publishable, NMI tokenization, Square application id. */
        public ?string $public_key = null,

        /** Authorize.Net only — `Accept.dispatchData` needs this ALONGSIDE the client key. */
        public ?string $api_login_id = null,

        /**
         * Authorize.Net only — the SDK URL differs by environment and loading
         * the wrong one fails at tokenise-time with an opaque error, so the
         * server decides it rather than the browser guessing.
         */
        public ?string $accept_js_url = null,

        /** Square Web Payments SDK requires this alongside the application id. */
        public ?string $location_id = null,

        /** Whether the gateway will vault the card for reuse. */
        public bool $vaulting_enabled = false,
    ) {}

    public static function forAccount(MerchantAccount $account): self
    {
        $environment = $account->environment ?? GatewayEnvironment::Sandbox;
        $isSandbox = $environment === GatewayEnvironment::Sandbox;

        $base = [
            'merchant_account_id' => $account->uuid,
            'gateway_provider' => $account->gateway_provider->value,
            'environment' => $environment->value,
        ];

        return match ($account->gateway_provider) {
            GatewayProvider::AuthorizeNet => new self(
                ...$base,
                public_key: $account->authnet_public_client_key,
                api_login_id: $account->authnet_api_login_id,
                accept_js_url: $isSandbox
                    ? 'https://jstest.authorize.net/v1/Accept.js'
                    : 'https://js.authorize.net/v1/Accept.js',
                vaulting_enabled: (bool) ($account->cim_enabled ?? false),
            ),
            GatewayProvider::Stripe => new self(
                ...$base,
                public_key: $account->stripe_publishable_key,
            ),
            GatewayProvider::Square => new self(
                ...$base,
                public_key: $account->square_application_id,
                location_id: $account->square_location_id,
            ),
            GatewayProvider::Nmi => new self(
                ...$base,
                public_key: $account->nmi_public_key,
            ),
        };
    }

    /** False when the gateway cannot tokenise with what is configured. */
    public function isUsable(): bool
    {
        if ($this->public_key === null || $this->public_key === '') {
            return false;
        }

        return match ($this->gateway_provider) {
            GatewayProvider::AuthorizeNet->value => (bool) $this->api_login_id,
            GatewayProvider::Square->value => (bool) $this->location_id,
            default => true,
        };
    }
}
