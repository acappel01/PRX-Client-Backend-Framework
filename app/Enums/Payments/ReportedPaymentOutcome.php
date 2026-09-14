<?php

namespace App\Enums\Payments;

/** Producer claims only; never a verified financial state. */
enum ReportedPaymentOutcome: string
{
    case Unknown = 'unknown';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Settled = 'settled';
    case Refunded = 'refunded';
    case Voided = 'voided';
    case Declined = 'declined';
}
