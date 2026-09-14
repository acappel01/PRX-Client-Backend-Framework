<?php

namespace App\Actions\Attribution;

use App\Integrations\ConsentResolver;
use App\Integrations\Contracts\ReadsEmailSuppression;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Messages\EmailSuppressionRead;
use App\Models\Attribution\EmailSuppressionObservation;
use App\Models\Integrations\IntegrationInstance;
use App\Models\Lead;
use App\Services\Attribution\EmailSuppressionEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit internal read. No route, workflow, worker, subscription or sending activation. */
class ReadEmailSuppressionAction
{
    public function __construct(private readonly EmailSuppressionEvidence $evidence, private readonly ConsentResolver $consent, private readonly IntegrationRegistry $registry) {}

    public function execute(int $leadId, int $instanceId): EmailSuppressionObservation
    {
        if (DB::transactionLevel() !== 0) {
            throw ValidationException::withMessages(['suppression' => 'Run the read outside an existing database transaction.']);
        }
        [$lead, $instance, $identity, $binding, $allowed, $request] = DB::transaction(function () use ($leadId, $instanceId): array {
            $instance = IntegrationInstance::withTrashed()->lockForUpdate()->findOrFail($instanceId);
            $lead = Lead::query()->lockForUpdate()->findOrFail($leadId);
            $identity = $this->evidence->identity($lead, $instance);

            $binding = $this->evidence->binding($lead, $instance, $identity);
            $request = EmailSuppressionObservation::create([
                'lead_id' => $leadId, 'integration_instance_id' => $instanceId,
                'status' => 'unknown', 'reason' => 'read_pending',
                'evidence' => ['version' => 1, 'binding' => $binding, 'revision' => 'none'],
                'started_at' => now()->utc(), 'recorded_at' => now()->utc(),
            ]);

            return [$lead, $instance, $identity, $binding,
                $this->evidence->available($lead, $instance, $identity) && $this->consent->resolve($lead, currentRead: true)->grants('email'), $request];
        });
        $startedAt = $request->started_at;
        $read = new EmailSuppressionRead('unknown', 'read_scope_or_consent_missing', 'none');
        if ($allowed) {
            $driver = $this->registry->driverFor($instance);
            if ($driver instanceof ReadsEmailSuppression) {
                $read = $driver->readEmailSuppression($instance, $instance->settings['suppression_read']['account_id'], $identity->remote_id, $lead->email);
            }
        }

        return DB::transaction(function () use ($leadId, $instanceId, $binding, $read, $startedAt, $request): EmailSuppressionObservation {
            $instance = IntegrationInstance::withTrashed()->lockForUpdate()->findOrFail($instanceId);
            $lead = Lead::query()->lockForUpdate()->find($leadId);
            $identity = $lead === null ? null : $this->evidence->identity($lead, $instance);
            $valid = $lead !== null && $this->evidence->available($lead, $instance, $identity)
                && hash_equals($binding, $this->evidence->binding($lead, $instance, $identity))
                && $this->consent->resolve($lead, currentRead: true)->grants('email');

            return EmailSuppressionObservation::create([
                'lead_id' => $lead?->id, 'integration_instance_id' => $instanceId, 'request_id' => $request->id,
                'status' => $valid ? $read->status : 'unknown', 'reason' => $valid ? $read->reason : 'local_context_changed',
                'evidence' => ['version' => 1, 'binding' => $binding, 'revision' => $read->revision],
                'started_at' => $startedAt, 'recorded_at' => now()->utc(),
            ]);
        });
    }
}
