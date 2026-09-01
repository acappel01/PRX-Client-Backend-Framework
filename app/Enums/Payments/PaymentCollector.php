<?php

namespace App\Enums\Payments;

/**
 * WHO TAKES THE MONEY. One setting, so two systems cannot both believe they
 * are collecting — the failure mode that produces a double charge or none.
 *
 * It is the ONLY input to whether the storefront renders its own payment step
 * and whether the provider's embed renders theirs. The two are derived from
 * this, never configured independently.
 *
 * The choice is constrained by the provider's own contract: their `authorize`
 * mode is EMBED-ONLY, because a sales-org API token is not permitted to run a
 * pre-auth. So "provider collects" necessarily means the embed's payment step,
 * and "we collect" means we send a record-only payment block describing a
 * charge we already ran.
 */
enum PaymentCollector: string
{
    /**
     * The provider's embed takes payment. Their payment step renders, we send
     * no payment block, and no merchant account is needed on this side.
     */
    case Provider = 'provider';

    /**
     * We take payment on the storefront through the configured merchant
     * account, then tell the provider about it. Their payment step is skipped
     * and the intake carries `payment.mode = reference_captured` — a
     * record-only audit trail of a charge that already succeeded here.
     */
    case Storefront = 'storefront';

    public function label(): string
    {
        return match ($this) {
            self::Provider => 'The telehealth provider collects payment',
            self::Storefront => 'We collect payment on our own checkout',
        };
    }

    public function helperText(): string
    {
        return match ($this) {
            self::Provider => 'Payment is taken inside the clinical intake embed. Nothing is charged on this site and no merchant account is required.',
            self::Storefront => 'Payment is taken on our checkout through the configured merchant account, then reported to the provider as already captured. Requires a working merchant account.',
        };
    }

    /**
     * The provider's `payment.mode` for a submission we collected ourselves.
     *
     * Never `authorize`: their contract restricts that to embed-form tokens,
     * and a sales-org token is refused.
     */
    public function providerPaymentMode(): ?string
    {
        return match ($this) {
            self::Provider => null,
            self::Storefront => 'reference_captured',
        };
    }
}
