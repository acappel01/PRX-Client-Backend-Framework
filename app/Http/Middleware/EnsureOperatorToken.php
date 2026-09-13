<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator counterpart of EnsurePatientToken.
 *
 * `auth:sanctum` accepts any token-bearing model — an operator, a patient or an
 * API client — so a route meant for operators needs this as well. Without it a
 * patient token reached `/api/v1/auth/me` and 500'd on `getRoleNames()`, a
 * method only operators have.
 */
class EnsureOperatorToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
