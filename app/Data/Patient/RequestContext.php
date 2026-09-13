<?php

namespace App\Data\Patient;

use Illuminate\Http\Request;

/**
 * Where a request came from: the client IP and user agent, as the server sees
 * them.
 *
 * The IP is `$request->ip()`, which honours TRUSTED_PROXIES — so behind the
 * portal it is the patient, not the portal host. Nothing here is read from a
 * request body. The user agent is caller-controlled text and is treated as such:
 * scrubbed to valid UTF-8 (a strict-mode insert would reject anything else, and
 * the event would be lost) and capped to its column.
 */
final readonly class RequestContext
{
    public const USER_AGENT_MAX = 512;

    public ?string $userAgent;

    public function __construct(
        public ?string $ip = null,
        ?string $userAgent = null,
    ) {
        $this->userAgent = self::cleanUserAgent($userAgent);
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->ip(), $request->userAgent());
    }

    private static function cleanUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $clean = rtrim(mb_substr(trim(mb_scrub($userAgent, 'UTF-8')), 0, self::USER_AGENT_MAX, 'UTF-8'));

        return $clean === '' ? null : $clean;
    }
}
