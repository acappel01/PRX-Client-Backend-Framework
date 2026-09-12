<?php

namespace App\Events\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A patient proved control of an order's mailbox and their account is now
 * linked to the record that order created.
 *
 * Dispatched after the claim transaction commits, so a workflow never acts on
 * a link that rolled back. Carries the patient only: the chart id is
 * identifying and is not registered as a workflow field.
 */
class RecordClaimed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Patient $patient) {}
}
