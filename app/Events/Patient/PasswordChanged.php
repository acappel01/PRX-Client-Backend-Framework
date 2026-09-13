<?php

namespace App\Events\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A patient set a new password with an emailed reset link, and every session
 * was signed out.
 *
 * Not named PasswordReset: `Illuminate\Auth\Events\PasswordReset` exists and a
 * listener imported from the wrong namespace would silently never fire.
 *
 * 🔴 Carries the patient and nothing else — never the token, never the password.
 */
class PasswordChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Patient $patient) {}
}
