<?php

namespace App\Enums\Webhooks;

/**
 * Where an inbound webhook is in its life.
 *
 * `Unmatched` is not a failure: the event was valid but names a record this
 * install does not hold (yet). It is kept so it can be replayed once the record
 * exists — `php artisan webhooks:replay --status=unmatched`.
 */
enum InboundWebhookStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Unmatched = 'unmatched';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processing => 'Processing',
            self::Processed => 'Processed',
            self::Unmatched => 'Unmatched',
            self::Ignored => 'Ignored',
            self::Failed => 'Failed',
        };
    }
}
