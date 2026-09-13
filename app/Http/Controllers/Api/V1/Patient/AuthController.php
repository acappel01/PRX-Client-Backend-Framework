<?php

namespace App\Http\Controllers\Api\V1\Patient;

use App\Actions\Exceptions\ActionException;
use App\Actions\Patient\CreatePatientAccountAction;
use App\Actions\Patient\LoginPatientAction;
use App\Actions\Patient\RequestAccountLinkAction;
use App\Actions\Patient\ResetPatientPasswordAction;
use App\Data\Patient\PatientResource;
use App\Http\Controllers\Api\V1\ApiController;
use App\Jobs\Patient\SendAccountLinkJob;
use App\Services\Patient\PatientMail;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Patient authentication — account links, login, logout, me.
 *
 * Separate guard from the admin User auth. Patients authenticate
 * against the `patients` table; tokens carry `patient:*` abilities.
 */
class AuthController extends ApiController
{
    /** What an anonymous visitor is told, whatever exists under the address. */
    public const LINK_SENT = 'Check your email. If that address has an order or an account with us, we have sent it a link.';

    public const PASSWORD_CHANGED = 'Your password has been changed and you have been signed out everywhere. Sign in with your new password.';

    /**
     * Start an account: email a link to the address.
     *
     * Creates NOTHING. Registration used to create an unverified account from a
     * typed address, which let anyone register a customer's email first and
     * lock them out. Now the link creates the account, and only for an address
     * with a claimable order; an address that already has an account is sent a
     * password-reset link instead. The answer is the same 202 either way, and
     * the decision runs in a queued job so its timing cannot tell them apart.
     * See RequestAccountLinkAction.
     *
     * Only `email` is read. A 503 describes the installation (no portal URL, no
     * usable mail integration, no queue worker), never the address.
     *
     * @tags PatientAuth
     *
     * @unauthenticated
     */
    public function register(Request $request, PatientMail $mail): JsonResponse
    {
        return $this->requestAccountLink($request, $mail);
    }

    /**
     * Forgot password: email a reset link — or, for an address with an order and
     * no account yet, a create-account link. Identical to `register` on purpose.
     *
     * @tags PatientAuth
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request, PatientMail $mail): JsonResponse
    {
        return $this->requestAccountLink($request, $mail);
    }

    /**
     * Use a create-account link. The account's email is the address the link
     * was sent to — none is accepted here. Returns a session, as login does.
     *
     * @tags PatientAuth
     *
     * @unauthenticated
     */
    public function createAccount(Request $request, CreatePatientAccountAction $action): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        ['patient' => $patient, 'token' => $token] = $action->execute(
            $validated['token'],
            $validated['password'],
            $validated['device_name'] ?? ($request->userAgent() ?? 'api'),
            $request->ip(),
        );

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'patient' => PatientResource::fromModel($patient)->toArray(),
        ], status: 201);
    }

    /**
     * Use a password-reset link. Signs every session out and does NOT sign in:
     * the link proves the mailbox, which must not stand in for a second factor.
     *
     * @tags PatientAuth
     *
     * @unauthenticated
     */
    public function resetPassword(Request $request, ResetPatientPasswordAction $action): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $action->execute($validated['token'], $validated['password'], $request->ip());

        return response()->json(['data' => ['changed' => true], 'message' => self::PASSWORD_CHANGED]);
    }

    /**
     * Authenticate a patient and issue a Sanctum token.
     *
     * Returns a `patient:*`-scoped bearer token. The token is shown only once.
     *
     * @tags PatientAuth
     *
     * @unauthenticated
     */
    public function login(Request $request, LoginPatientAction $action): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            ['patient' => $patient, 'token' => $token] = $action->execute(
                $validated['email'],
                $validated['password'],
                $validated['device_name'] ?? ($request->userAgent() ?? 'api'),
            );
        } catch (AuthenticationException $e) {
            throw ValidationException::withMessages(['email' => [$e->getMessage()]]);
        }

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'patient' => PatientResource::fromModel($patient)->toArray(),
        ]);
    }

    /**
     * Revoke the current patient token.
     *
     * @tags PatientAuth
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(['message' => 'Token revoked.']);
    }

    /**
     * Return the authenticated patient's profile.
     *
     * @tags PatientAuth
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(PatientResource::fromModel($request->user())->toArray());
    }

    private function requestAccountLink(Request $request, PatientMail $mail): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        try {
            $mail->deliveryOrFail('Account link');
            $mail->queueOrFail('Account link');
        } catch (ActionException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }

        SendAccountLinkJob::dispatch(
            RequestAccountLinkAction::normalise($validated['email']),
            $request->ip(),
        );

        return response()->json(['data' => ['sent' => true], 'message' => self::LINK_SENT], 202);
    }
}
