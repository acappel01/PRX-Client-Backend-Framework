<?php

namespace App\Events\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A patient's email address was proven for the first time.
 *
 * Separate from RecordClaimed on purpose: today the claim is the only thing
 * that verifies an address, but a later plain verification flow will fire this
 * without linking anything, and a workflow about "verified accounts" should
 * not have to change when it does.
 */
class EmailVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Patient $patient) {}
}
