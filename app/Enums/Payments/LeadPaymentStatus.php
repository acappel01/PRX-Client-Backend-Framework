<?php

namespace App\Enums\Payments;

/**
 * Where this visitor's card got to, and therefore whether they may proceed.
 *
 * `allowsIntake()` is the safety property this enum exists for: a declined
 * card must not reach a clinical intake, because a completed encounter means a
 * clinician's time spent and product shipped for money nobody took.
 */
enum LeadPaymentStatus: string
{
    /** Nothing attempted — the provider is collecting, so there is nothing to gate on. */
    case None = 'none';

    /** Funds held, not taken. The provider captures once the encounter completes. */
    case Authorized = 'authorized';

    /** Money taken. The provider is told the charge already succeeded. */
    case Captured = 'captured';

    case Failed = 'failed';

    /**
     * Whether a lead in this state may enter the clinical intake.
     *
     * `None` passes because it means this deployment is not collecting at all —
     * the provider's own checkout step handles payment inside the embed. The
     * caller decides whether payment was REQUIRED; this only answers whether
     * what happened is good enough to continue.
     */
    public function allowsIntake(): bool
    {
        return $this !== self::Failed;
    }

    /** Whether a card actually succeeded, as opposed to never being attempted. */
    public function isSettled(): bool
    {
        return $this === self::Authorized || $this === self::Captured;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No payment taken here',
            self::Authorized => 'Authorised, awaiting capture',
            self::Captured => 'Captured',
            self::Failed => 'Failed',
        };
    }
}
