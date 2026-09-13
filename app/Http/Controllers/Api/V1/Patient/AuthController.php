<?php

namespace App\Http\Controllers\Api\V1\Patient;

use App\Actions\Exceptions\ActionException;
use App\Actions\Patient\CompleteTwoFactorLoginAction;
use App\Actions\Patient\CreatePatientAccountAction;
use App\Actions\Patient\LoginPatientAction;
use App\Actions\Patient\LogoutPatientAction;
use App\Actions\Patient\RequestAccountLinkAction;
use App\Actions\Patient\ResetPatientPasswordAction;
use App\Data\Patient\PatientResource;
use App\Data\Patient\RequestContext;
use App\Http\Controllers\Api\V1\ApiController;
use App\Jobs\Patient\SendAccountLinkJob;
use App\Services\Patient\PatientMail;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

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
            RequestContext::fromRequest($request),
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

        $action->execute($validated['token'], $validated['password'], RequestContext::fromRequest($request));

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
            'trusted_device_token' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $action->execute(
                $validated['email'],
                $validated['password'],
                $validated['device_name'] ?? ($request->userAgent() ?? 'api'),
                RequestContext::fromRequest($request),
                $validated['trusted_device_token'] ?? null,
            );
        } catch (AuthenticationException $e) {
            throw ValidationException::withMessages(['email' => [$e->getMessage()]]);
        }

        // Two-step verification on: no session and nothing about the account
        // until the code is given at `two-factor`.
        if (isset($result['challenge'])) {
            return $this->success([
                'two_factor_required' => true,
                'challenge' => $result['challenge'],
                'expires_at' => $result['expires_at']->toIso8601String(),
                'methods' => ['totp', 'recovery_code'],
            ]);
        }

        $session = [
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'patient' => PatientResource::fromModel($result['patient'])->toArray(),
        ];

        // Present only when a trusted browser skipped the code: its renewed
        // expiry, so the client can extend its stored token to match.
        if ($result['trusted_device_expires_at'] !== null) {
            $session['trusted_device'] = ['expires_at' => $result['trusted_device_expires_at']->toIso8601String()];
        }

        return $this->success($session);
    }

    /**
     * Finish a two-step sign-in.
     *
     * Send the `challenge` from `login` with either `code` (6 digits from the
     * authenticator app) or `recovery_code`. Returns a session exactly as
     * `login` does without two-step verification. Every refusal is the same 422
     * on `code`; too many wrong codes for the account is a 429. A challenge
     * lasts five minutes and allows five attempts. `trust_device: true` also
     * returns `trusted_device.token` (once) — sent with the password at `login`
     * as `trusted_device_token`, it skips the code on that browser for the
     * install's trusted-device days, renewed on each use.
     *
     * @tags PatientAuth
     *
     * @unauthenticated
     */
    public function twoFactor(Request $request, CompleteTwoFactorLoginAction $action): JsonResponse
    {
        $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:32'],
            'trust_device' => ['sometimes', 'boolean'],
        ]);

        try {
            ['patient' => $patient, 'token' => $token, 'trusted_device' => $trusted] = $action->execute(
                $request->input('challenge'),
                $request->input('code'),
                $request->input('recovery_code'),
                RequestContext::fromRequest($request),
                $request->boolean('trust_device'),
            );
        } catch (ActionException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'patient' => PatientResource::fromModel($patient)->toArray(),
            // Shown once. The client keeps it (httpOnly) and sends it with the
            // password next time as `trusted_device_token`.
            'trusted_device' => $trusted === null ? null : [
                'token' => $trusted['token'],
                'expires_at' => $trusted['expires_at']->toIso8601String(),
            ],
        ]);
    }

    /**
     * Revoke the current patient token.
     *
     * @tags PatientAuth
     */
    public function logout(Request $request, LogoutPatientAction $action): JsonResponse
    {
        $session = $request->user()->currentAccessToken();

        if ($session instanceof PersonalAccessToken) {
            $action->execute($request->user(), $session, RequestContext::fromRequest($request));
        }

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
            RequestContext::fromRequest($request)->userAgent,
        );

        return response()->json(['data' => ['sent' => true], 'message' => self::LINK_SENT], 202);
    }
}
