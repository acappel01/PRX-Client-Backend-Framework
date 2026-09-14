<?php

namespace App\Integrations\Messages;

/** Bounded result: no contact, credentials, raw body or vendor error text. */
final readonly class EmailSuppressionRead
{
    public function __construct(public string $status, public string $reason, public string $revision) {}
}
