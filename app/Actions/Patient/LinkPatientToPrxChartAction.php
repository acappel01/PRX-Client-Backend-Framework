<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Models\Commerce\Encounter;
use App\Models\Lead;
use App\Models\Patient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place a local Patient is linked to a Prescribe-Rx chart.
 *
 * Until this existed the only runtime writer of `patients.prx_patient_chart_id`
 * was a text input on the Filament patient form, so every real patient needed
 * an operator to paste a chart id before the portal would show them anything.
 *
 * ── The evidence is the ENCOUNTER, and that is the whole design ─────────────
 *
 * The obvious implementation is the vulnerable one, and the first version of
 * this action shipped it: take the lead, resolve its email against the provider,
 * link whatever chart comes back. That is a complete account takeover, because
 * **`POST /leads` is anonymous and returns the uuid** (`routes/api.php:193`,
 * `LeadResource:20` — it has to be, it is the checkout form itself). So:
 *
 *   1. register with the victim's address — nothing verifies it;
 *   2. `POST /leads` with that same address, and read the uuid out of the 201;
 *   3. claim it — the emails match, the lead is unclaimed, and the provider
 *      happily resolves the address to the victim's chart.
 *
 * A lead is therefore NOT proof of a transaction. Anyone can mint one for any
 * address. What cannot be minted is an **encounter**: `Encounter` rows have
 * exactly two writers, and neither takes an identifier from an untrusted
 * caller —
 *
 *   * `SubmitPrescribeRxCheckoutAction` — our server called the provider and
 *     read `patient_chart_id` out of its response;
 *   * `UpsertEncounterAction`, reached only from the HMAC-verified webhook
 *     (`PrescribeRxWebhookController`, `hash_equals` on `X-PRX-Signature`).
 *
 * So the chart id is taken from the encounter and nowhere else. `leads`
 * carries its own `prescribe_rx_patient_id`, and it is deliberately ignored:
 * `LeadIntakeController::complete` and `EmbedCompleteController` both write it
 * with no credential, from the request body.
 *
 * ── Mailbox proof lives one level up ────────────────────────────────────────
 *
 * The encounter proves an order is real; it does not prove the account holder
 * placed it. That proof is a single-use link emailed to the order's own address,
 * consumed by one of the only two callers — `ClaimPatientRecordAction` (a
 * signed-in account) or `CreatePatientAccountAction` (the account created by the
 * link) — which call this inside their own transaction.
 * Do not expose this action to a request directly again — the endpoint that
 * did (`POST /patient/link-chart`) was removed for exactly that reason.
 */
class LinkPatientToPrxChartAction
{
    use Transacts;

    /**
     * Link `$patient` to the chart their order created, or explain why not.
     *
     * @throws ValidationException
     */
    public function execute(Patient $patient, Lead $lead): Patient
    {
        // Not the resolution key — that is the encounter — but still required.
        // It keeps a stray uuid from being enough on its own.
        if ($lead->email === null || mb_strtolower($lead->email) !== mb_strtolower($patient->email)) {
            throw ValidationException::withMessages([
                'lead_uuid' => 'That order does not belong to this account.',
            ]);
        }

        // Idempotent: the same account re-claiming the lead it already holds is
        // a no-op, so a double-submit is harmless rather than a confusing 422.
        if ($lead->patient_id === $patient->getKey() && $patient->prx_patient_chart_id !== null) {
            return $patient;
        }

        if ($patient->prx_patient_chart_id !== null) {
            // Re-linking would silently move a patient's clinical history onto a
            // different chart. That is an operator decision, never a self-serve
            // one.
            throw ValidationException::withMessages([
                'lead_uuid' => 'This account is already linked to a medical record.',
            ]);
        }

        if ($lead->patient_id !== null) {
            throw ValidationException::withMessages([
                'lead_uuid' => 'That order has already been claimed.',
            ]);
        }

        $chartId = $this->chartFromEncounter($lead);

        try {
            return $this->tx(function () use ($patient, $lead, $chartId): Patient {
                // Re-read under a lock: two concurrent claims on one lead must
                // not both pass the checks above. A primary-key lock on one row
                // — deliberately NOT a locking read on the unique chart index,
                // which under REPEATABLE-READ takes a GAP lock for a value that
                // does not exist yet and deadlocks two unrelated first-time
                // claims against each other.
                $locked = Lead::whereKey($lead->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->patient_id !== null) {
                    throw ValidationException::withMessages([
                        'lead_uuid' => 'That order has already been claimed.',
                    ]);
                }

                $patient->forceFill([
                    'prx_patient_chart_id' => $chartId,
                    // Stamped only on this path. It means a server-side check
                    // agreed, which the Filament text input cannot say.
                    'prx_chart_verified_at' => now(),
                ])->save();

                $locked->forceFill(['patient_id' => $patient->getKey()])->save();

                return $patient->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // `patients.prx_patient_chart_id` is unique, and the index is the
            // real arbiter — checking first would only narrow the race, and
            // holding a lock to close it is what caused the deadlock above.
            // Two accounts reaching the same chart is a support case either
            // way; it must not be a 500.
            Log::warning('Refused to link a PRX chart already held by another patient.', [
                'patient_id' => $patient->getKey(),
                'lead_uuid' => $lead->uuid,
            ]);

            throw ValidationException::withMessages([
                'lead_uuid' => 'That medical record is already linked to another account.',
            ]);
        }
    }

    /**
     * The chart id, taken from a row only our own server or a signed webhook
     * could have written.
     */
    private function chartFromEncounter(Lead $lead): string
    {
        $chartId = Encounter::where('lead_id', $lead->getKey())
            ->whereNotNull('prescribe_rx_patient_id')
            ->orderBy('id')
            ->value('prescribe_rx_patient_id');

        if (! is_string($chartId) || $chartId === '') {
            // Covers both "no consultation has been submitted for this order"
            // and "one has, but the provider has not told us the chart yet".
            // Neither is something to guess at.
            throw ValidationException::withMessages([
                'lead_uuid' => 'We could not find a medical record for that order yet. It may still be processing.',
            ]);
        }

        // The lead's own copy is written by two unauthenticated endpoints, so a
        // disagreement means someone pointed this lead at a different chart.
        // The encounter still wins; this is here to make the attempt visible.
        if ($lead->prescribe_rx_patient_id !== null && $lead->prescribe_rx_patient_id !== $chartId) {
            Log::warning('Lead chart id disagrees with its encounter; using the encounter.', [
                'lead_uuid' => $lead->uuid,
                'lead_value' => $lead->prescribe_rx_patient_id,
                'encounter_value' => $chartId,
            ]);
        }

        return $chartId;
    }
}
