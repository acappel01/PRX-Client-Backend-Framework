<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Events\Patient\TwoFactorRemoved;
use App\Jobs\Patient\SendTwoFactorNoticeJob;
use App\Models\Patient;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\TrustedDevices;

/**
 * Support turns off two-step verification for a patient who lost their phone and
 * their recovery codes.
 *
 * 🔴 THIS IS AN IDENTITY DECISION THE SYSTEM CANNOT MAKE. Whoever presses the
 * button has to be satisfied they are speaking to the account holder; the admin
 * records WHO pressed it, not that anyone checked. The action says so where it
 * is offered.
 *
 * Every session is signed out and the account's address is told. Under the
 * `required` policy the patient is sent to set it up again on their next screen.
 */
class ResetPatientTwoFactorAction
{
    use Transacts;

    public function __construct(
        private readonly PatientSecurityLog $log,
        private readonly TrustedDevices $trustedDevices,
    ) {}

    public function execute(Patient $patient, int $operatorId, ?RequestContext $client = null): void
    {
        $revoked = $this->tx(function () use ($patient): int {
            DisableTwoFactorAction::clear($patient);

            return $patient->tokens()->delete();
        });

        $this->log->record(
            SecurityEventType::TwoFactorRemoved,
            patient: $patient,
            client: $client,
            actor: SecurityEventActor::Operator,
            actorUserId: $operatorId,
            context: ['reason' => 'operator_reset'],
        );

        $this->log->record(
            SecurityEventType::SessionsRevoked,
            patient: $patient,
            client: $client,
            actor: SecurityEventActor::Operator,
            actorUserId: $operatorId,
            context: ['reason' => 'two_factor_reset', 'revoked' => $revoked],
        );

        $this->trustedDevices->revokeAll($patient, 'two_factor_reset', $client, SecurityEventActor::Operator, $operatorId);

        TwoFactorRemoved::dispatch($patient);
        SendTwoFactorNoticeJob::dispatch($patient->getKey(), SendTwoFactorNoticeJob::RESET_BY_SUPPORT);
    }
}
