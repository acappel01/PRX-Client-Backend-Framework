<?php

namespace App\Actions\Attribution;

use App\Models\Attribution\CanonicalEvent;
use App\Models\Customer;
use App\Models\Lead;
use App\Services\Attribution\CanonicalEventRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Trusted, local-only recording. No delivery, subscription, identity merge or provider call. */
class RecordCanonicalEventAction
{
    public function __construct(private readonly CanonicalEventRegistry $registry) {}

    /**
     * References are caller-supplied persisted model snapshots. Their in-memory
     * state is checked; this is not a locking freshness or authorization check.
     * Capture producers pass the newly inserted Lead in the same transaction.
     */
    public function execute(
        string $name,
        string $source,
        string $dedupeKey,
        CarbonInterface $occurredAt,
        array $payload,
        ?Lead $lead = null,
        ?Customer $customer = null,
        string $origin = 'admin',
        string $environment = 'local',
        int $schemaVersion = 1,
    ): CanonicalEvent {
        $payload = $this->registry->validate($name, $schemaVersion, $payload);
        $identity = ['origin' => $origin, 'environment' => $environment, 'source' => $source, 'dedupe_key' => $dedupeKey];
        Validator::make($identity, [
            'origin' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'environment' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'source' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'dedupe_key' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9:_.-]*$/'],
        ])->validate();
        foreach ([$lead, $customer] as $subject) {
            if ($subject !== null && (! $subject->exists || $subject->getKey() === null || $subject->trashed())) {
                throw ValidationException::withMessages(['event' => 'Event references must identify persisted active records.']);
            }
        }
        $occurredAt = CarbonImmutable::instance($occurredAt)->utc();
        $business = [
            'name' => $name, 'schema_version' => $schemaVersion,
            'lead_id' => $lead?->getKey(), 'customer_id' => $customer?->getKey(),
            'occurred_at' => $occurredAt, 'payload' => $payload,
        ];

        return DB::transaction(function () use ($identity, $business, $occurredAt): CanonicalEvent {
            $event = CanonicalEvent::query()->where($identity)->first();
            if ($event === null) {
                try {
                    // A savepoint contains a racing unique insert on Postgres.
                    $event = DB::transaction(fn () => CanonicalEvent::create($identity + $business + [
                        'event_id' => (string) Str::uuid(), 'recorded_at' => CarbonImmutable::now()->utc(),
                    ]));
                } catch (UniqueConstraintViolationException $exception) {
                    // A current read also sees the winner when an enclosing
                    // MySQL repeatable-read transaction has an older snapshot.
                    $event = CanonicalEvent::query()->where($identity)->lockForUpdate()->first();
                    if ($event === null) {
                        throw $exception;
                    }
                }
            }
            if ($event->name !== $business['name'] || $event->schema_version !== $business['schema_version']
                || $event->lead_id !== $business['lead_id'] || $event->customer_id !== $business['customer_id']
                || ! $event->occurred_at->equalTo($occurredAt) || $event->payload !== $business['payload']) {
                throw ValidationException::withMessages(['event' => 'This event identity already describes a different occurrence.']);
            }

            return $event;
        }, 3);
    }
}
