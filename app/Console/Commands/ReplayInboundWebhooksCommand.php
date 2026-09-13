<?php

namespace App\Console\Commands;

use App\Enums\Webhooks\InboundWebhookStatus;
use App\Jobs\ProcessInboundWebhookEvent;
use App\Models\InboundWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Re-queue recorded webhooks through their handler.
 *
 * The usual cases: `unmatched` events once the record they named exists here,
 * and `failed` events once the bug that failed them is fixed. Replaying is safe
 * because handlers are idempotent and an older event never overwrites a newer
 * status. A `processed` or `ignored` event is never re-queued in bulk; name it
 * by uuid to force one.
 */
class ReplayInboundWebhooksCommand extends Command
{
    protected $signature = 'webhooks:replay
        {uuid? : Replay one event, whatever its status}
        {--status=unmatched : Replay every event in this status (unmatched or failed)}
        {--source= : Only this source, e.g. prescribe-rx}
        {--since= : Only events received after this, e.g. "2 days ago"}
        {--dry-run : List what would be replayed}';

    protected $description = 'Re-queue recorded inbound webhooks (unmatched or failed) through their handler';

    public function handle(): int
    {
        if ($uuid = $this->argument('uuid')) {
            $event = InboundWebhookEvent::query()->where('uuid', $uuid)->first();

            if (! $event) {
                $this->error("No inbound webhook event {$uuid}.");

                return self::FAILURE;
            }

            return $this->replay(collect([$event]));
        }

        $status = InboundWebhookStatus::tryFrom((string) $this->option('status'));

        if (! in_array($status, [InboundWebhookStatus::Unmatched, InboundWebhookStatus::Failed], true)) {
            $this->error('--status must be unmatched or failed. Name a single event by uuid to replay anything else.');

            return self::FAILURE;
        }

        $events = InboundWebhookEvent::query()
            ->where('status', $status->value)
            ->when($this->option('source'), fn ($q, $source) => $q->where('source', $source))
            ->when($this->option('since'), fn ($q, $since) => $q->where('created_at', '>=', Carbon::parse($since)))
            ->orderBy('id')
            ->get();

        return $this->replay($events);
    }

    /**
     * @param  Collection<int, InboundWebhookEvent>  $events
     */
    private function replay($events): int
    {
        foreach ($events as $event) {
            $this->line(sprintf('  %s  %-10s %-28s %s', $event->uuid, $event->status->value, $event->event_type, (string) $event->subject_ref));

            if (! $this->option('dry-run')) {
                $event->forceFill(['status' => InboundWebhookStatus::Received, 'error' => null])->save();
                ProcessInboundWebhookEvent::dispatch($event->id);
            }
        }

        $this->info(sprintf('%d event(s) %s.', $events->count(), $this->option('dry-run') ? 'would be replayed' : 're-queued'));

        return self::SUCCESS;
    }
}
