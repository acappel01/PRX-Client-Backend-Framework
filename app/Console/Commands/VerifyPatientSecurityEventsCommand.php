<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Check every patient security event against its signature.
 *
 * Two checks per row:
 *   - the HMAC over its immutable columns matches (current or previous APP_KEY);
 *   - where `patient_id` is still set, that patient's uuid equals the signed
 *     `patient_uuid` — `patient_id` is unsigned because its foreign key nulls
 *     it, so this is what catches a row re-pointed at another patient.
 *
 * A failure is `Log::critical` with row ids only. What it CANNOT detect is a
 * deleted row; see PatientSecurityEvent.
 */
class VerifyPatientSecurityEventsCommand extends Command
{
    protected $signature = 'patient-security-events:verify';

    protected $description = 'Verify patient security events have not been edited since they were written';

    public function handle(): int
    {
        $checked = 0;

        /** @var list<int> $tampered */
        $tampered = [];

        PatientSecurityEvent::query()->chunkById(500, function ($events) use (&$checked, &$tampered): void {
            $uuids = Patient::withTrashed()
                ->whereIn('id', $events->pluck('patient_id')->filter()->unique())
                ->pluck('uuid', 'id');

            foreach ($events as $event) {
                $checked++;

                $pointsElsewhere = $event->patient_id !== null
                    && $uuids->get($event->patient_id) !== $event->patient_uuid;

                if ($pointsElsewhere || ! $event->hasValidIntegrity()) {
                    $tampered[] = $event->getKey();
                }
            }
        });

        if ($tampered !== []) {
            Log::critical('Patient security events failed verification.', [
                'count' => count($tampered),
                'event_ids' => array_slice($tampered, 0, 100),
            ]);

            $this->error(count($tampered)." of {$checked} events failed verification: ".implode(', ', array_slice($tampered, 0, 100)));

            return self::FAILURE;
        }

        $this->info("{$checked} events verified.");

        return self::SUCCESS;
    }
}
