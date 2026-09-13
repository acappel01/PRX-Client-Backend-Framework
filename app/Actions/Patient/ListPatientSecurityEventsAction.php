<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use Illuminate\Support\Collection;

/**
 * A patient's own security history, newest first.
 *
 * By `patient_id` only. Events that name an address but no account — a failed
 * sign-in before the account existed, a create-account link — share the
 * address hash and are deliberately NOT joined in: the address was typed by
 * someone unproven, and this list is shown to whoever holds the session.
 */
class ListPatientSecurityEventsAction
{
    public const MAX_LIMIT = 100;

    /**
     * @return Collection<int, PatientSecurityEvent>
     */
    public function execute(Patient $patient, int $limit = 20): Collection
    {
        return PatientSecurityEvent::query()
            ->where('patient_id', $patient->getKey())
            ->latest('occurred_at')
            ->latest('id')
            ->limit(max(1, min(self::MAX_LIMIT, $limit)))
            ->get();
    }
}
