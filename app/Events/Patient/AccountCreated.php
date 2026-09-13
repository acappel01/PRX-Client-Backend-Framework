<?php

namespace App\Events\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A patient account was created — which, since registration stopped creating
 * rows, only ever happens by using an emailed create-account link. The account
 * is therefore verified and connected to its order's record by the time this
 * fires; RecordClaimed and EmailVerified fire alongside it.
 *
 * 🔴 Carries the patient and nothing else. The link that created the account
 * is a credential and never travels on an event.
 */
class AccountCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Patient $patient) {}
}
