<?php

namespace App\Observers;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientAuthChallenge;
use App\Models\User;
use App\Services\Patient\PatientSecurityLog;

/**
 * Security events for changes made to a patient account from OUTSIDE the
 * patient's own flows — an operator editing, deleting or restoring it.
 *
 * ── Edits are recorded only when an operator made them ──────────────────────
 *
 * The patient's own flows set the same columns (claiming a record sets the chart
 * id) and record their own, more specific events. So `updated` writes only when
 * a signed-in admin user is present on the `web` guard. An edit from tinker, a
 * script or a query-builder update bypasses this, which is stated rather than
 * pretended otherwise — as are `saveQuietly()` and `withoutEvents()`.
 *
 * ── Deletion ends sessions ──────────────────────────────────────────────────
 *
 * Sanctum resolves a token's patient through a relation the soft-delete scope
 * filters, so a deleted patient's token already fails — but the token rows stay,
 * and come back live on restore. They are deleted here, so a restored account
 * starts signed out. Deletion is recorded whoever did it.
 */
class PatientSecurityObserver
{
    public const REASON_ACCOUNT_DELETED = 'account_deleted';

    public function __construct(private readonly PatientSecurityLog $log) {}

    public function updated(Patient $patient): void
    {
        $operator = $this->operator();

        if ($operator === null) {
            return;
        }

        if ($patient->wasChanged('email')) {
            // Hashed under the NEW address; the old one is not stored anywhere.
            $this->record(SecurityEventType::EmailChanged, $patient, $operator);
        }

        if ($patient->wasChanged('prx_patient_chart_id')) {
            $this->record(SecurityEventType::ChartLinkChanged, $patient, $operator);
        }
    }

    public function deleted(Patient $patient): void
    {
        // A force delete also passes through here; forceDeleting() records it
        // while the row still exists to reference.
        if ($patient->isForceDeleting()) {
            return;
        }

        $revoked = $patient->tokens()->delete();
        PatientAuthChallenge::voidOutstandingFor($patient);

        $this->record(SecurityEventType::AccountDeleted, $patient, $this->operator(), [
            'reason' => self::REASON_ACCOUNT_DELETED,
            'revoked' => $revoked,
        ]);
    }

    public function forceDeleting(Patient $patient): void
    {
        // Before the delete, so the insert can reference the row; the foreign
        // key then nulls `patient_id` and `patient_uuid` keeps naming it.
        $patient->tokens()->delete();

        $this->record(SecurityEventType::AccountPurged, $patient, $this->operator());
    }

    public function restored(Patient $patient): void
    {
        $this->record(SecurityEventType::AccountRestored, $patient, $this->operator());
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function record(SecurityEventType $type, Patient $patient, ?User $operator, array $context = []): void
    {
        $this->log->record(
            $type,
            patient: $patient,
            client: app()->runningInConsole() ? null : RequestContext::fromRequest(request()),
            actor: $operator === null ? SecurityEventActor::System : SecurityEventActor::Operator,
            actorUserId: $operator?->getKey(),
            context: $context,
        );
    }

    private function operator(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }
}
