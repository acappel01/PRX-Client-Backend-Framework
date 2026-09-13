<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use App\Services\PrescribeRx\Client;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IssuePortalTokenAction
{
    /**
     * Least-privilege patient ability set, per TokenAbility::forUserType(PATIENT)
     * (prx-demo@07969f8, app/Enums/Api/TokenAbility.php:159-172). Explicit list so
     * future PRX additions don't silently widen our tokens.
     *
     * PRX caps a requested ability to that set and REJECTS anything outside it
     * (IssuePatientTokenAction::resolveAbilities, 422) — so an over-broad entry
     * here breaks every mint rather than escalating. The under-broad direction is
     * the one that fails quietly: an omitted ability 403s at the endpoint that
     * needs it, days later, in one screen. Four were omitted and each cost a
     * feature — approve-charge (provider-added charge approvals), scheduling:read
     * and :write (slot lookup and booking), lab:read (results and biomarkers).
     */
    private const PATIENT_ABILITIES = [
        'patient:read',
        'patient:update',
        'patient:approve-charge',
        'patient:vitals',
        'order:read',
        'encounter:read',
        'prescription:read',
        'telehealth:read',
        'telehealth:submit',
        'product:read',
        'scheduling:read',
        'scheduling:write',
        'lab:read',
    ];

    /** Evict from cache this many seconds before PRX expires the token (clock-skew guard). */
    private const TTL_BUFFER_SECONDS = 60;

    public function __construct(private readonly Client $prx) {}

    /**
     * Return a valid PRX patient token, minting a new one only when the cache is cold.
     */
    public function execute(Patient $patient): string
    {
        if (! $patient->hasPrxChart()) {
            throw new \RuntimeException('Patient has no linked PRX chart. Complete intake first.');
        }

        return Cache::get($this->cacheKey($patient)) ?? $this->mint($patient);
    }

    /**
     * Force-evict the cache and mint a fresh token. Use after a PRX 401.
     */
    public function renew(Patient $patient): string
    {
        $this->forget($patient);

        return $this->mint($patient);
    }

    /**
     * Evict the cached token without minting a replacement.
     */
    public function forget(Patient $patient): void
    {
        Cache::forget($this->cacheKey($patient));
    }

    private function mint(Patient $patient): string
    {
        $response = $this->prx->issuePatientToken($patient->prx_patient_chart_id, self::PATIENT_ABILITIES);

        $ttl = $this->ttlFromExpiresAt($response['expires_at'] ?? null);

        Cache::put($this->cacheKey($patient), $response['token'], $ttl);

        $this->recordProviderIdentifiers($patient, $response);

        return $response['token'];
    }

    /**
     * Keep the provider's identifiers for this patient on our record, so support
     * can find the account from the number the provider's staff quote.
     *
     * Only when one is missing or has changed, and never at the cost of the
     * token: a failure here is logged and the patient's request carries on.
     * Identifiers only — name, date of birth and phone are read live
     * (operator decision 2026-09-13).
     *
     * @param  array<string, mixed>  $issued
     */
    private function recordProviderIdentifiers(Patient $patient, array $issued): void
    {
        // Stub mode would write placeholder ids into real rows on a dev box.
        if (config('prescribe-rx.stub')) {
            return;
        }

        try {
            $patientId = self::identifier($issued['patient_id'] ?? null) ?? $patient->prx_patient_id;

            // The id is already in hand: keep it even if the number lookup fails.
            if ($patientId !== $patient->prx_patient_id) {
                $patient->forceFill(['prx_patient_id' => $patientId])->save();
            }

            if ($patient->prx_patient_number === null) {
                $number = self::identifier($this->prx->getMyPatientChart($issued['token'])['patient_number'] ?? null);

                if ($number !== null) {
                    $patient->forceFill(['prx_patient_number' => $number])->save();
                }
            }
        } catch (StrayRequestException $e) {
            // A test that forgot to fake the provider must fail, not log and pass.
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Could not record the provider identifiers for a patient.', [
                'patient_id' => $patient->getKey(),
                'exception' => $e::class,
            ]);
        }
    }

    private function cacheKey(Patient $patient): string
    {
        return 'prx_patient_token:'.$patient->prx_patient_chart_id;
    }

    private function ttlFromExpiresAt(?string $expiresAt): int
    {
        if ($expiresAt) {
            $remaining = (int) now()->diffInSeconds(Carbon::parse($expiresAt), absolute: false);

            return max(30, $remaining - self::TTL_BUFFER_SECONDS);
        }

        return 1500; // 25-min fallback for stub mode
    }

    /** An identifier as the provider sent it, or null — rejected, never truncated. */
    private static function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value) === 1 ? $value : null;
    }
}
