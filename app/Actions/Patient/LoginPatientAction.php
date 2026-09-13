<?php

namespace App\Actions\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\PatientSessionLifetime;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

/**
 * Check a patient's password and open a session — recording the attempt either
 * way.
 *
 * ── One answer, one cost ────────────────────────────────────────────────────
 *
 * An unknown address and a wrong password throw the same exception. They also
 * cost the same: an address with no account still spends one bcrypt, because
 * skipping it answered in microseconds where a real account took hundreds of
 * milliseconds, which told anyone timing the request which addresses had
 * accounts. Both branches also write one event through the same call.
 *
 * ── Failures for addresses with no account are recorded too ───────────────
 *
 * With no patient to attach to, the event carries only a keyed hash of the
 * address, so repeated attempts against one address — or from one IP across
 * many — can be seen without storing what was typed. `context.reason` says
 * which failure it was; it is internal and never returned to the caller.
 */
class LoginPatientAction
{
    public function __construct(
        private readonly PatientSecurityLog $log,
        private readonly PatientSessionLifetime $lifetime,
    ) {}

    /**
     * @return array{patient: Patient, token: string}
     *
     * @throws AuthenticationException
     */
    public function execute(string $email, string $password, string $deviceName = 'api', ?RequestContext $client = null): array
    {
        $patient = Patient::where('email', $email)->first();

        if ($patient === null) {
            // Spend the work a real check would. The result is discarded.
            Hash::make($password);

            $this->failed(null, $email, $client, 'unknown_account');
        }

        if (! Hash::check($password, $patient->password)) {
            $this->failed($patient, $email, $client, 'bad_password');
        }

        // `patient:*`, the same as every other patient session. It was `['*']`,
        // which nothing checks today (EnsurePatientToken tests the model type),
        // but a token that claims every ability is one refactor from meaning it.
        $session = $patient->createToken($deviceName, ['patient:*'], $this->lifetime->expiresAt());

        $this->log->record(
            SecurityEventType::LoginSucceeded,
            patient: $patient,
            client: $client,
            tokenId: $session->accessToken->getKey(),
        );

        return ['patient' => $patient, 'token' => $session->plainTextToken];
    }

    /**
     * @throws AuthenticationException always
     */
    private function failed(?Patient $patient, string $email, ?RequestContext $client, string $reason): never
    {
        $this->log->record(
            SecurityEventType::LoginFailed,
            patient: $patient,
            client: $client,
            actor: SecurityEventActor::Anonymous,
            email: $email,
            context: ['reason' => $reason],
        );

        throw new AuthenticationException('The provided credentials are incorrect.');
    }
}
