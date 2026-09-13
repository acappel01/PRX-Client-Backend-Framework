<?php

namespace App\Jobs;

use App\Contracts\Webhooks\InboundWebhookHandler;
use App\Enums\Webhooks\InboundWebhookStatus;
use App\Models\InboundWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Applies one recorded webhook through its source's handler.
 *
 * UNMATCHED IS RETRIED A FEW TIMES, briefly. A provider can announce a record
 * before our own write of it has committed — prescribe-rx sends
 * `encounter.created` from inside the intake call our checkout is still waiting
 * on — so the first attempt can legitimately find nothing. After the last
 * attempt the row stays `unmatched` and `webhooks:replay` picks it up.
 *
 * A handler exception marks the row `failed` and is NOT rethrown: the provider
 * was answered when the event was recorded, and a queue retry of a bug only
 * repeats it. Fix, then replay.
 *
 * `$tries` is set here because the default Horizon supervisor runs `tries => 1`,
 * and a `release()` counts as an attempt.
 */
class ProcessInboundWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** Seconds before re-checking an unmatched event, per attempt. */
    private const UNMATCHED_BACKOFF = [30, 120, 600];

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = InboundWebhookEvent::find($this->eventId);

        if (! $event || in_array($event->status, [InboundWebhookStatus::Processed, InboundWebhookStatus::Ignored], true)) {
            return;
        }

        $handlerClass = config("webhooks.handlers.{$event->source}");

        if (! is_string($handlerClass) || ! is_subclass_of($handlerClass, InboundWebhookHandler::class)) {
            $this->markFailed($event, "No webhook handler is registered for source [{$event->source}].");

            return;
        }

        $event->forceFill([
            'status' => InboundWebhookStatus::Processing,
            'attempts' => $event->attempts + 1,
        ])->save();

        try {
            $outcome = app($handlerClass)->handle($event);
        } catch (Throwable $e) {
            $this->markFailed($event, $e->getMessage());

            return;
        }

        $event->forceFill([
            'status' => $outcome->status,
            'matched_type' => $outcome->matched?->getMorphClass(),
            'matched_id' => $outcome->matched?->getKey(),
            'error' => null,
            'processed_at' => now(),
        ])->save();

        if ($outcome->status === InboundWebhookStatus::Unmatched && $this->attempts() < $this->tries) {
            $this->release(self::UNMATCHED_BACKOFF[$this->attempts() - 1] ?? 600);
        }
    }

    private function markFailed(InboundWebhookEvent $event, string $message): void
    {
        $event->forceFill([
            'status' => InboundWebhookStatus::Failed,
            'error' => Str::limit($message, 1000),
        ])->save();

        Log::error('inbound-webhook: processing failed', [
            'event_uuid' => $event->uuid,
            'source' => $event->source,
            'event_type' => $event->event_type,
            'message' => $message,
        ]);
    }
}
