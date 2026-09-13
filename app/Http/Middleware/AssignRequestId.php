<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One correlation id per API request: in our logs, on the calls we make to the
 * clinical provider (which echoes it in its `meta.request_id` and response
 * header), and back to the client in `X-Request-ID` — so a "Reference" a patient
 * reads from an error screen finds the request in our logs and can be quoted to
 * the provider. First in the priority list, so 401s and 429s carry it too.
 *
 * MINTED HERE, NEVER READ FROM THE CALLER. An inbound `X-Request-ID` is ignored:
 * accepting one would let a browser write arbitrary bytes into our logs and the
 * provider's. A v4 uuid is not PHI and not a credential.
 */
class AssignRequestId
{
    public const CONTAINER_KEY = 'request_id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();

        app()->instance(self::CONTAINER_KEY, $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }

    /** The current request's id, or a fresh one outside a request (queue, console). */
    public static function current(): string
    {
        return app()->bound(self::CONTAINER_KEY) ? (string) app(self::CONTAINER_KEY) : (string) Str::uuid();
    }
}
