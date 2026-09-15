<?php

namespace App\Data\Payments;

/** Internal raw request carrier. Never serialize, log or enqueue this object. */
final readonly class GatewayNotificationData
{
    /** @param list<string> $signature_headers Preserve original header cardinality. */
    public function __construct(public int $receiver_id, public string $raw_body, public array $signature_headers, public ?string $content_encoding = null) {}
}
