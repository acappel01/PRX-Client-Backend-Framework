<?php

namespace App\Events\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A claim link was emailed for one of this patient's orders.
 *
 * Fired only when a message actually went out — never for a request that
 * matched no order, so a workflow cannot be used to learn which addresses have
 * one.
 *
 * 🔴 CARRIES NO TOKEN, AND MUST NEVER GROW ONE. This is a workflow trigger, and
 * workflow context is serialised into a queued job and written to the run log,
 * where any admin with run-log access reads it. The link itself is sent by the
 * system (`RequestClaimLinkAction`), not by a workflow step, for exactly that
 * reason. Hang follow-ups here — never the delivery of the link.
 */
class ClaimLinkRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Patient $patient) {}
}
