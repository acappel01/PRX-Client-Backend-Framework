<?php

namespace App\Enums\Payments;

/**
 * WHO TAKES THE MONEY, AND IN WHAT ORDER. One setting, because the storefront
 * and the provider's embed both read it — so they can never both believe they
 * are collecting, which is the failure mode that produces a double charge or
 * none.
 *
 * It is ONE setting rather than two (a "who" plus a "when") deliberately: a
 * sequence only means anything when this side collects, so splitting them
 * would create a combination — provider collects, with a storefront sequence —
 * that has no behaviour. **When this is `Provider`, the storefront renders no
 * payment UI at all**; the embed's own checkout step handles everything.
 *
 * The two storefront sequences are the ones the operator actually runs, and
 * they map onto the provider's documented payment-bypass options:
 *
 *   - `CaptureThenIntake` — we charge on our checkout, then hand the intake a
 *     `transaction` block. The provider records the external charge, marks the
 *     order paid, and skips its checkout step. Money is taken before a
 *     clinician has seen the case.
 *   - `AuthorizeThenCapture` — we authorise and VAULT the card, hand the
 *     intake the resulting CIM profile, and the provider captures once the
 *     encounter is complete. Nothing is captured from a patient a clinician
 *     declines, and the vaulted card is theirs to rebill for subscriptions.
 *
 * BOTH STOREFRONT SEQUENCES REQUIRE THE SAME MERCHANT ACCOUNT ON BOTH SIDES.
 * The provider validates that at intake finalisation, which is what
 * `merchant_accounts.provider_merchant_profile_id` exists to satisfy — without
 * it a vaulted card is ours alone and they cannot rebill it.
 *
 * Their `authorize` mode is EMBED-ONLY (a sales-org token is refused), so
 * "we collect" always means we run the transaction and report it.
 */
enum PaymentCollector: string
{
    /** The provider's embed takes payment. The storefront shows no card form. */
    case Provider = 'provider';

    /** Sequence 1 — charge here, then intake; provider records the capture. */
    case CaptureThenIntake = 'capture_then_intake';

    /** Sequence 2 — authorise and vault here; provider captures after the encounter. */
    case AuthorizeThenCapture = 'authorize_then_capture';

    public function label(): string
    {
        return match ($this) {
            self::Provider => 'The telehealth provider collects payment',
            self::CaptureThenIntake => 'We charge at checkout, then the clinical intake runs',
            self::AuthorizeThenCapture => 'We authorise at checkout, the provider captures after review',
        };
    }

    public function helperText(): string
    {
        return match ($this) {
            self::Provider => 'Payment is taken inside the clinical intake embed. No card form appears on this site and no merchant account is needed.',
            self::CaptureThenIntake => 'The card is charged on our checkout before the intake begins, and the provider is told the payment already succeeded. Simplest to reason about, but money is taken before a clinician has reviewed the case.',
            self::AuthorizeThenCapture => 'The card is authorised and stored at checkout, and the provider captures it only once the encounter is complete. Nothing is taken from a patient a clinician declines, and the stored card is available to them for subscriptions and follow-ups.',
        };
    }

    /** True when this site renders a card form and the embed's step is skipped. */
    public function collectsOnSite(): bool
    {
        return $this !== self::Provider;
    }

    /**
     * The provider's payment-bypass shape for this sequence.
     *
     * `transaction` reports a charge that already succeeded; `vaultedCard`
     * hands over a stored CIM profile for them to capture and rebill against.
     * Null when they are collecting and there is nothing to bypass.
     */
    public function bypassShape(): ?string
    {
        return match ($this) {
            self::Provider => null,
            self::CaptureThenIntake => 'transaction',
            self::AuthorizeThenCapture => 'vaultedCard',
        };
    }

    /**
     * Tolerates the short-lived `storefront` value that predated the split
     * into two sequences. Capture-then-intake is the safe reading: it is what
     * a single "we collect" flag meant at the time.
     */
    public static function fromStored(?string $value): self
    {
        return match ($value) {
            'storefront' => self::CaptureThenIntake,
            default => self::tryFrom((string) $value) ?? self::Provider,
        };
    }
}
