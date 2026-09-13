<?php

namespace App\Events\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A patient turned on two-step verification (or replaced their authenticator).
 *
 * 🔴 Carries the patient and nothing else — never a secret, a code or a recovery code.
 */
class TwoFactorEnrolled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Patient $patient) {}
}
