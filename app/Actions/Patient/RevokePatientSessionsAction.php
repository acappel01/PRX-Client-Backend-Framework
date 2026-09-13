<?php

namespace App\Actions\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\TrustedDevices;

/**
 * Sign a patient out of every session — the operator's answer to "I think
 * someone else is in my account".
 *
 * The password is not changed: whoever signed in with it can sign in again. It
 * is the right first step while the patient resets their password, and the
 * operator is told so where the action is offered.
 */
class RevokePatientSessionsAction
{
    public const REASON_OPERATOR = 'operator';

    public function __construct(
        private readonly PatientSecurityLog $log,
        private readonly TrustedDevices $trustedDevices,
    ) {}

    /**
     * @return int How many sessions were ended.
     */
    public function execute(Patient $patient, int $operatorId, ?RequestContext $client = null): int
    {
        $revoked = $patient->tokens()->delete();

        $this->log->record(
            SecurityEventType::SessionsRevoked,
            patient: $patient,
            client: $client,
            actor: SecurityEventActor::Operator,
            actorUserId: $operatorId,
            context: ['reason' => self::REASON_OPERATOR, 'revoked' => $revoked],
        );

        // "Someone else may be in the account": their browser must not skip the code.
        $this->trustedDevices->revokeAll($patient, self::REASON_OPERATOR, $client, SecurityEventActor::Operator, $operatorId);

        return $revoked;
    }
}
