<?php

namespace App\Contracts\Webhooks;

use App\Data\Webhooks\InboundWebhookOutcome;
use App\Models\InboundWebhookEvent;

/**
 * Applies one recorded webhook from a single source. Registered per source in
 * `config/webhooks.php`, resolved by `ProcessInboundWebhookEvent`.
 *
 * Delivery is at-least-once and events can arrive out of order or be replayed,
 * so an implementation must be idempotent and must not let an older event
 * overwrite a newer one. Throwing marks the event `failed` (replayable); it is
 * never reported back to the provider, whose request was answered long before.
 */
interface InboundWebhookHandler
{
    public function handle(InboundWebhookEvent $event): InboundWebhookOutcome;
}
