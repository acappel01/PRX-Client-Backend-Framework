<?php

namespace App\Data\Webhooks;

use Carbon\CarbonImmutable;

/**
 * A provider's webhook envelope, reduced to what this install records.
 *
 * Built by a provider-specific parser (e.g. `PrescribeRxWebhookParser`) so the
 * ledger and the job never learn a provider's envelope shape.
 */
final readonly class ParsedInboundWebhook
{
    /**
     * @param  array<string, mixed>  $payload  allowlisted — never the raw body
     */
    public function __construct(
        public string $eventType,
        public ?string $providerEventId,
        public ?string $subjectType,
        public ?string $subjectRef,
        public ?CarbonImmutable $occurredAt,
        public array $payload,
    ) {}
}
