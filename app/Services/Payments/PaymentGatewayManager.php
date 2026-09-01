<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Enums\Payments\GatewayProvider;
use App\Models\Payments\MerchantAccount;
use App\Services\Payments\Gateways\AuthorizeNetGateway;
use App\Services\Payments\Gateways\NmiGateway;
use App\Services\Payments\Gateways\SquareGateway;
use App\Services\Payments\Gateways\StripeGateway;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PaymentGatewayManager
{
    /** @var array<string, class-string<PaymentGatewayInterface>> */
    protected array $gateways = [
        GatewayProvider::Nmi->value => NmiGateway::class,
        GatewayProvider::AuthorizeNet->value => AuthorizeNetGateway::class,
        GatewayProvider::Stripe->value => StripeGateway::class,
        GatewayProvider::Square->value => SquareGateway::class,
    ];

    public function __construct(protected Container $container) {}

    /**
     * Resolve the gateway implementation for the given merchant account.
     */
    public function forAccount(MerchantAccount $account): PaymentGatewayInterface
    {
        return $this->driver($account->gateway_provider);
    }

    /**
     * Resolve the gateway implementation for the given merchant account ID.
     */
    public function forAccountId(string $merchantAccountId): PaymentGatewayInterface
    {
        $account = MerchantAccount::query()->findOrFail($merchantAccountId);

        return $this->forAccount($account);
    }

    public function driver(GatewayProvider $provider): PaymentGatewayInterface
    {
        $class = $this->gateways[$provider->value]
            ?? throw new \InvalidArgumentException("No gateway registered for provider [{$provider->value}].");

        return $this->container->make($class);
    }

    /**
     * Resolve the configured default merchant account and its gateway.
     * Throws if no default account is active.
     */
    public function default(): PaymentGatewayInterface
    {
        return $this->forAccount($this->defaultAccount());
    }

    /**
     * Resolve the default active merchant account model.
     * Throws ModelNotFoundException if no default account is active.
     */
    /**
     * The account that should take a charge of this size.
     *
     * ROUTED, NOT FIXED. An install runs several accounts and moves between
     * them as they approach their processing limits, so this asks the router
     * rather than reading `is_default` — the default is only a tie-break now.
     * `$amount` matters: a charge that would breach an account's limit skips
     * it even while the account is otherwise healthy.
     *
     * Still `firstOrFail`-shaped for callers: no eligible account is an
     * exception, because a checkout with nowhere to send the money must fail
     * loudly rather than silently pick something over its limit.
     */
    public function defaultAccount(?float $amount = null): MerchantAccount
    {
        $account = app(MerchantRoutingService::class)->select($amount);

        if (! $account) {
            throw new ModelNotFoundException('No eligible merchant account is available to take this payment.');
        }

        return $account;
    }
}
