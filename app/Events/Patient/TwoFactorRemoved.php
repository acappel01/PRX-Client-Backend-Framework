<?php

namespace App\Events\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Two-step verification was turned off on a patient account — by the patient, or reset by support.
 *
 * 🔴 Carries the patient and nothing else — never a secret, a code or a recovery code.
 */
class TwoFactorRemoved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Patient $patient) {}
}
