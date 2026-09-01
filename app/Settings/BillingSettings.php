<?php

namespace App\Settings;

use App\Enums\Payments\PaymentCollector;
use Spatie\LaravelSettings\Settings;

class BillingSettings extends Settings
{
    /**
     * Which checkout provider handles order submission.
     *
     * 'prx'   — All orders go through Prescribe-Rx (embed handoff).
     *           No local payment processing; PRX collects payment inside its embed.
     * 'local' — Orders are charged locally through the configured merchant account
     *           (NMI / Authorize.Net / Stripe / Square). PRX is not involved in payment.
     */
    public string $checkout_path = 'prx';

    /**
     * WHO TAKES THE MONEY — the storefront, or the provider's embed.
     *
     * Distinct from `checkout_path`, which says who submits the ORDER. A
     * deployment can route orders through the provider while still taking the
     * card itself, so conflating the two would remove a real combination.
     *
     * Stored as the enum's string value; read it through
     * `paymentCollector()`.
     */
    public string $payment_collector = 'provider';

    /**
     * Show upsell / cross-sell suggestions (driven by catalog relations)
     * in the cart drawer and on the checkout page.
     */
    public bool $upsells_enabled = true;

    /** Maximum number of upsell suggestions returned per request. */
    public int $upsells_limit = 4;

    public function paymentCollector(): PaymentCollector
    {
        return PaymentCollector::tryFrom($this->payment_collector) ?? PaymentCollector::Provider;
    }

    /**
     * True when this site renders its own payment step and the provider's is
     * skipped. Single source of truth for both sides of that decision.
     */
    public function collectsPaymentOnSite(): bool
    {
        return $this->paymentCollector() === PaymentCollector::Storefront;
    }

    public static function group(): string
    {
        return 'billing';
    }
}
