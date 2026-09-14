<?php

namespace App\Actions\Attribution;

use App\Enums\Integrations\IntegrationCapability;
use App\Integrations\ConsentResolver;
use App\Integrations\Contracts\TracksEvents;
use App\Integrations\IntegrationRegistry;
use App\Models\Attribution\CanonicalDelivery;
use App\Models\Attribution\CanonicalDeliveryEvaluation;
use App\Models\Attribution\CanonicalEvent;
use App\Models\Integrations\IntegrationInstance;
use App\Models\Lead;
use App\Services\Attribution\CanonicalEventRegistry;
use App\Services\Attribution\EmailSuppressionEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit internal preview only. Never resolves/calls a vendor driver or dispatches a job. */
class PreviewCanonicalDeliveryAction
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly ConsentResolver $consent,
        private readonly CanonicalEventRegistry $events,
        private readonly EmailSuppressionEvidence $suppression,
    ) {}

    public function execute(int $eventId, int $instanceId): CanonicalDeliveryEvaluation
    {
        return DB::transaction(function () use ($eventId, $instanceId): CanonicalDeliveryEvaluation {
            $event = CanonicalEvent::query()->lockForUpdate()->findOrFail($eventId);
            $instance = IntegrationInstance::withTrashed()->lockForUpdate()->findOrFail($instanceId);
            $delivery = CanonicalDelivery::query()->where([
                'canonical_event_id' => $event->id, 'integration_instance_id' => $instance->id,
                'operation' => 'track_event',
            ])->lockForUpdate()->first() ?? CanonicalDelivery::create([
                'delivery_id' => (string) Str::uuid(), 'canonical_event_id' => $event->id,
                'integration_instance_id' => $instance->id, 'operation' => 'track_event', 'recorded_at' => now()->utc(),
            ]);
            $reasons = [];
            $definition = $this->registry->provider($instance->provider);
            if ($instance->trashed() || ! $instance->is_active
                || ! $this->registry->instanceOffers($instance, IntegrationCapability::Crm)
                || ! is_a($definition['driver'] ?? '', TracksEvents::class, true)) {
                $reasons[] = 'destination_unavailable';
            }
            $policy = $instance->settings['canonical_event_preview'] ?? null;
            if (! is_array($policy) || ($policy['version'] ?? null) !== 1
                || ($policy['environment'] ?? null) !== $event->environment
                || ! is_array($policy['events'] ?? null) || ! in_array($event->name, $policy['events'], true)) {
                $reasons[] = 'destination_policy_missing';
            }
            $lead = $event->lead_id === null ? null : Lead::query()->lockForUpdate()->find($event->lead_id);
            if ($lead === null) {
                $reasons[] = 'subject_unavailable';
            } elseif (! $this->consent->resolve($lead, currentRead: true)->grants('email')) {
                $reasons[] = 'email_consent_missing';
            } elseif (! filter_var($lead->email, FILTER_VALIDATE_EMAIL)) {
                $reasons[] = 'email_identity_missing';
            }
            $observation = $this->suppression->current($lead, $instance, $event->environment);
            $suppressionStatus = $observation?->status ?? 'unknown';
            if ($suppressionStatus !== 'clear') {
                $reasons[] = $suppressionStatus === 'suppressed' ? 'remote_marketing_blocked' : 'suppression_unknown';
            }
            // A fresh read qualifies a preview only. No sender is implemented or activated.
            $policyEligible = $reasons === [];
            $reasons[] = 'delivery_disabled';
            $payload = $this->events->validate($event->name, $event->schema_version, $event->payload);
            $allowedGoals = is_array($policy) && is_array($policy['goal_keys'] ?? null) ? $policy['goal_keys'] : [];
            $projection = [
                'event_id' => $event->event_id, 'name' => $event->name,
                'occurred_at' => $event->occurred_at->toISOString(),
                // No arbitrary UTM text, quiz identifiers, Lead UUID, contact, value or vendor identifiers.
                'goal_keys' => array_values(array_filter($payload['goal_keys'],
                    fn (string $goal): bool => in_array($goal, $allowedGoals, true))),
            ];

            return CanonicalDeliveryEvaluation::create([
                'canonical_delivery_id' => $delivery->id, 'evaluation_schema_version' => 1,
                'policy_evidence' => [
                    'configuration_fingerprint' => hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)),
                    'destination_active' => ! $instance->trashed() && $instance->is_active,
                    'subject_present' => $lead !== null,
                    'suppression' => $suppressionStatus,
                    'suppression_observation_id' => $observation?->id,
                    'policy_eligible' => $policyEligible,
                ],
                'status' => 'blocked', 'reasons' => $reasons, 'projection' => $projection,
                'evaluated_at' => now()->utc(),
            ]);
        }, 3);
    }
}
