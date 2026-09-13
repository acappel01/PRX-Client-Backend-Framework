<?php

namespace App\Services\PrescribeRx\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Wraps every failure mode of the prescribe-rx HTTP client into one
 * exception type.
 *
 * Two consumers, and they want different things from it. The Action layer
 * catches it and renders a single toast pattern in the Filament panels. The
 * API renders it centrally in `bootstrap/app.php`, where `httpStatus` decides
 * the status a portal client sees and `errors` is forwarded on a 422 — so a
 * status set here is a status a patient's screen will act on. Pick it for what
 * the CALLER should do, not for where the failure happened to be detected.
 */
class PrescribeRxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $errors = null,
        ?Throwable $previous = null,
        // The provider's correlation id for the failed call. Normally the id we
        // sent it (it echoes `X-Request-ID`); differs only if it minted its own.
        public readonly ?string $upstreamRequestId = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'PrescribeRx integration is not configured. Set the API token in /admin/settings/integrations.',
            httpStatus: 0,
        );
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json() ?? [];

        $message = $body['message']
            ?? "PrescribeRx API call failed (HTTP {$response->status()})";

        $upstreamRequestId = $body['meta']['request_id'] ?? $response->header('X-Request-ID');

        return new self(
            $message,
            httpStatus: $response->status(),
            errors: $body['errors'] ?? null,
            // Provider-authored bytes: kept only if they look like an id, so
            // nothing else (or invalid UTF-8 that would break json()) reaches a body.
            upstreamRequestId: is_string($upstreamRequestId) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $upstreamRequestId) === 1 ? $upstreamRequestId : null,
        );
    }
}
