<?php

namespace App\Data\Webhooks;

use App\Enums\Webhooks\InboundWebhookStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * What a handler did with one event: the ledger status to record and, when it
 * touched a row, which one.
 */
final readonly class InboundWebhookOutcome
{
    public function __construct(
        public InboundWebhookStatus $status,
        public ?Model $matched = null,
    ) {}

    public static function processed(Model $matched): self
    {
        return new self(InboundWebhookStatus::Processed, $matched);
    }

    public static function unmatched(): self
    {
        return new self(InboundWebhookStatus::Unmatched);
    }

    public static function ignored(): self
    {
        return new self(InboundWebhookStatus::Ignored);
    }
}
