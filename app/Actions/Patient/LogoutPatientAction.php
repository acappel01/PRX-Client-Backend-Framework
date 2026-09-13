<?php

namespace App\Actions\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Services\Patient\PatientSecurityLog;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * End the session a request arrived on. Other sessions are untouched — signing
 * out everywhere is RevokePatientSessionsAction.
 */
class LogoutPatientAction
{
    public function __construct(private readonly PatientSecurityLog $log) {}

    public function execute(Patient $patient, PersonalAccessToken $session, ?RequestContext $client = null): void
    {
        $tokenId = $session->getKey();

        $session->delete();

        $this->log->record(
            SecurityEventType::Logout,
            patient: $patient,
            client: $client,
            tokenId: $tokenId,
        );
    }
}
