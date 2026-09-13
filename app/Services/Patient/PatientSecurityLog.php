<?php

namespace App\Services\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way to write a patient security event.
 *
 * ── Never fails the thing it records ────────────────────────────────────────
 *
 * A broken log must not lock patients out, so a failed write is caught — but it
 * is never silent: `Log::critical` with the event type and the exception class
 * only. No address, IP or message text, because a database
 * exception's message embeds its bindings.
 *
 * ── Synchronous, after the action's transaction ─────────────────────────────
 *
 * Not queued: a queued payload would carry the IP (so would need encrypting), a
 * stopped worker would lose events without a trace, and order matters in a
 * security history. Callers record AFTER their transaction commits, so an event
 * describes something that happened, and a failed insert cannot disturb the
 * action's own writes on a driver that aborts a transaction on error.
 */
class PatientSecurityLog
{
    /**
     * @param  string|null  $email  The address the request was about. Hashed,
     *                              never stored; defaults to the patient's own.
     * @param  array<string, scalar|null>  $context  Small and type-specific:
     *                                               `reason`, `revoked`, `method`.
     */
    public function record(
        SecurityEventType $type,
        ?Patient $patient = null,
        ?RequestContext $client = null,
        SecurityEventActor $actor = SecurityEventActor::Patient,
        ?int $actorUserId = null,
        ?int $tokenId = null,
        ?string $email = null,
        array $context = [],
    ): ?PatientSecurityEvent {
        try {
            $address = $email ?? $patient?->email;

            return PatientSecurityEvent::create([
                'patient_id' => $patient?->getKey(),
                'patient_uuid' => $patient?->uuid,
                'subject_hash' => filled($address) ? PatientSecurityEvent::subjectHash($address) : null,
                'type' => $type,
                'actor_type' => $actor,
                'actor_user_id' => $actorUserId,
                'token_id' => $tokenId,
                'ip_address' => $client?->ip,
                'user_agent' => $client?->userAgent,
                'context' => $context,
            ]);
        } catch (Throwable $e) {
            // Not report($e): the exception handler logs the message, and an
            // insert's message quotes its bindings — the IP and user agent.
            Log::critical('Patient security event not recorded.', [
                'type' => $type->value,
                'exception' => $e::class,
            ]);

            return null;
        }
    }
}
