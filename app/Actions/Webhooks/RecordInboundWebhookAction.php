<?php

namespace App\Actions\Webhooks;

use App\Data\Webhooks\ParsedInboundWebhook;
use App\Enums\Webhooks\InboundWebhookStatus;
use App\Jobs\ProcessInboundWebhookEvent;
use App\Models\InboundWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records one authenticated webhook and queues it. Call it only AFTER the
 * signature has been verified — this is a write path.
 *
 * Returns null for a delivery already recorded (a provider retry, or a replay
 * of the same body), which the caller acknowledges with a 2xx so the provider
 * stops retrying. The unique `dedupe_key` index decides that, not a prior
 * SELECT, so two concurrent retries cannot both get in.
 */
class RecordInboundWebhookAction
{
    public function execute(string $source, ParsedInboundWebhook $parsed, string $rawBody): ?InboundWebhookEvent
    {
        $payloadHash = hash('sha256', $rawBody);

        try {
            $event = InboundWebhookEvent::create([
                'source' => $source,
                'provider_event_id' => $parsed->providerEventId,
                'event_type' => $parsed->eventType,
                'subject_type' => $parsed->subjectType,
                'subject_ref' => $parsed->subjectRef,
                'occurred_at' => $parsed->occurredAt,
                'payload_hash' => $payloadHash,
                'dedupe_key' => $this->dedupeKey($source, $parsed, $payloadHash),
                'payload' => $parsed->payload,
                'status' => InboundWebhookStatus::Received,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        ProcessInboundWebhookEvent::dispatch($event->id);

        return $event;
    }

    /**
     * The provider's delivery id when it signed one into the body; otherwise a
     * composite that only a byte-identical redelivery of the same event matches.
     * (prescribe-rx signs `webhook_id`; a stable per-event id is their PRX-3.)
     */
    private function dedupeKey(string $source, ParsedInboundWebhook $parsed, string $payloadHash): string
    {
        if ($parsed->providerEventId !== null) {
            return hash('sha256', $source.'|id|'.$parsed->providerEventId);
        }

        return hash('sha256', implode('|', [
            $source,
            $parsed->eventType,
            (string) $parsed->subjectRef,
            (string) $parsed->occurredAt?->toIso8601String(),
            $payloadHash,
        ]));
    }
}
