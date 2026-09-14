<?php

namespace App\Actions\Attribution;

use App\Models\Attribution\AttributionTouchpoint;
use App\Models\Attribution\CanonicalEvent;
use App\Services\Attribution\CanonicalEventRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordCaptureTouchpointAction
{
    public function execute(int $eventId): AttributionTouchpoint
    {
        return DB::transaction(function () use ($eventId): AttributionTouchpoint {
            // Parent lock serializes this single touchpoint identity, including MySQL current reads.
            $event = CanonicalEvent::query()->lockForUpdate()->findOrFail($eventId);
            if ($event->name !== CanonicalEventRegistry::LEAD_CAPTURED || $event->source !== 'lead.capture') {
                throw ValidationException::withMessages(['event' => 'Only original lead capture creates a capture touchpoint.']);
            }
            $payload = app(CanonicalEventRegistry::class)->validate($event->name, $event->schema_version, $event->payload);

            foreach ($payload['source'] as $value) {
                if ($value !== null && (preg_match('/[\\x00-\\x1f\\x7f?&#=]|:\\/\\//u', $value)
                    || str_starts_with($value, '/')
                    || preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value))) {
                    throw ValidationException::withMessages(['event' => 'Capture source contains unsupported URL or identifier material.']);
                }
            }

            return AttributionTouchpoint::query()->where('canonical_event_id', $event->id)->lockForUpdate()->first()
                ?? AttributionTouchpoint::create([
                    'touchpoint_id' => (string) Str::uuid(), 'canonical_event_id' => $event->id,
                    'evidence' => 'original_submission_unverified', 'source_tuple' => $payload['source'],
                    'recorded_at' => now()->utc(), 'occurred_at' => $event->occurred_at,
                ]);
        }, 3);
    }
}
