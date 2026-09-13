<?php

namespace App\Models;

use App\Enums\Webhooks\InboundWebhookStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One signed webhook accepted from a provider, recorded before it is acted on.
 * Written by `App\Actions\Webhooks\RecordInboundWebhookAction` and processed by
 * `App\Jobs\ProcessInboundWebhookEvent`; see the migration for why `payload` is
 * an allowlist and never the raw body.
 *
 * Retention: settled rows (processed, ignored, unmatched) go after
 * RETENTION_DAYS. `failed` rows are never pruned — they are the ones somebody
 * still has to look at.
 */
class InboundWebhookEvent extends Model
{
    use MassPrunable;

    public const RETENTION_DAYS = 90;

    protected $fillable = [
        'source',
        'provider_event_id',
        'event_type',
        'subject_type',
        'subject_ref',
        'occurred_at',
        'payload_hash',
        'dedupe_key',
        'payload',
        'status',
        'attempts',
        'error',
        'matched_type',
        'matched_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => InboundWebhookStatus::class,
            'attempts' => 'integer',
            'matched_id' => 'integer',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InboundWebhookEvent $event): void {
            $event->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The provider's `data` object, allowlisted.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return (array) ($this->payload ?? []);
    }

    public function prunable(): Builder
    {
        return static::query()
            ->whereIn('status', [
                InboundWebhookStatus::Processed->value,
                InboundWebhookStatus::Ignored->value,
                InboundWebhookStatus::Unmatched->value,
            ])
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
