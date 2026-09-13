<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePatientToken;
use App\Http\Middleware\EnsurePatientTwoFactorEnrolled;
use App\Http\Middleware\NoStorePhiResponse;
use App\Http\Middleware\VerifyApiClientOrigin;
use App\Services\PrescribeRx\Exceptions\PrescribeRxException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Inbound webhooks are signed via HMAC and have no browser session,
        // so they're CSRF-exempt. Signature verification happens in the
        // VerifyPrescribeRxSignature middleware on the route itself.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
        ]);

        $middleware->appendToGroup('api', VerifyApiClientOrigin::class);
        $middleware->appendToGroup('api', AssignRequestId::class);

        $middleware->alias([
            'patient' => EnsurePatientToken::class,
            'patient.2fa' => EnsurePatientTwoFactorEnrolled::class,
            'no-store' => NoStorePhiResponse::class,
        ]);

        // Laravel sorts route middleware by its priority list, which hoists the
        // authenticator to the front regardless of the order a route declares.
        // That left `no-store` INSIDE the auth check, so a 401 or 403 — the
        // responses that echo an id back to an unauthenticated caller — came
        // back with Laravel's default `no-cache, private` and was storable.
        //
        // The anchor is the CONTRACT, not Illuminate\Auth\Middleware\Authenticate:
        // the default priority list holds the interface, so naming the concrete
        // class matches nothing and the entry is silently appended to the END of
        // the list — the exact opposite of what was asked for, with no error.
        // Asserted by PortalResponseHeadersTest.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: NoStorePhiResponse::class,
        );

        // The correlation id goes first of all, so a 401 from Sanctum, a 429
        // from the throttle and a 500 thrown inside either still carry
        // `X-Request-ID`. Left to its group position it ran AFTER those, which
        // the priority sort hoists — the same trap as `no-store` above.
        $middleware->prependToPriorityList(
            before: NoStorePhiResponse::class,
            prepend: AssignRequestId::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every upstream failure arrives here as one exception type, and until
        // now every one of them rendered as a bare 500 "Server Error". That
        // collapsed two outcomes a client MUST tell apart:
        //
        //   * a 422 — the patient's own input was rejected, nothing was
        //     written, and correcting the value and resubmitting is right;
        //   * a 5xx — the provider crashed, and on at least one endpoint it
        //     crashes AFTER committing the row (`POST /me/patient/vitals`,
        //     P0-7), so resubmitting duplicates a clinical reading.
        //
        // Same status, same body. A vitals form built on that can only either
        // invite a duplicate or dead-end a typo. So: preserve the upstream
        // status, and pass the validation ERRORS through.
        //
        // What is never passed through is the upstream MESSAGE on a 5xx. The
        // provider returns its own stack in those — absolute paths, and the
        // full SQL statement with a patient_chart_id in it.
        $exceptions->render(function (PrescribeRxException $e, Request $request) {
            if (! $request->is('api/*')) {
                // The Filament panels catch this themselves and render a toast
                // that operators are used to. Only the API contract changes.
                return null;
            }

            $status = $e->httpStatus ?: 0;

            // A correlation id on every provider failure, so support can find
            // the request in our logs and the provider's. `upstream_request_id`
            // appears only when the provider used a different id than ours.
            $reference = array_filter([
                'request_id' => AssignRequestId::current(),
                'upstream_request_id' => $e->upstreamRequestId !== AssignRequestId::current() ? $e->upstreamRequestId : null,
            ]);

            if ($status === 422) {
                return response()->json([
                    'message' => 'Some of those values could not be accepted.',
                    // Keyed by the REQUEST field names, which is what the
                    // caller sent, so a form can point at the field. Validation
                    // text names fields and rules and carries nothing else.
                    'errors' => $e->errors ?? [],
                ] + $reference, 422);
            }

            // Deliberately NO 401 here. By the time this exception escapes,
            // `PortalController::withPatientToken()` has already evicted the
            // cached patient token and re-minted one with the ORG credential,
            // so a second 401 means the provider rejected OUR token, not the
            // visitor's — and the visitor is, by definition, already
            // authenticated with us or the request never reached a controller.
            // Answering 401 tells every portal screen to say "your session
            // expired"; the patient signs in, that succeeds, and they land on
            // the same message. Rotating the provider token without updating
            // IntegrationSettings would put the whole portal in that loop.
            // An upstream 401 is a configuration fault, so it belongs in 502.
            $passthrough = [
                403 => 'That is not available on this account.',
                404 => 'We could not find that.',
                409 => 'That conflicts with something already recorded.',
                429 => 'Too many requests. Please wait a moment.',
            ];

            if (isset($passthrough[$status])) {
                return response()->json(['message' => $passthrough[$status]] + $reference, $status);
            }

            // Anything else — including a status of 0, which is our own
            // "integration not configured" — is an upstream fault, not the
            // caller's. 502 says so without describing it.
            return response()->json([
                'message' => 'The clinical provider did not complete that request.',
            ] + $reference, $status === 0 ? 503 : 502);
        });
    })->create();
