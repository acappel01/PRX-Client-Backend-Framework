<?php

namespace App\Data\Leads;

use Spatie\LaravelData\Data;

/** Only hashes cross into persistence; the independent owner secret is never stored. */
class LeadSubmissionData extends Data
{
    public function __construct(
        public string $key,
        public string $ownerHash,
        public string $fingerprint,
    ) {}

    public static function forRequest(string $key, #[\SensitiveParameter] string $secret, array $payload, ?string $cartToken): self
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonicalize, $value);
        };

        return new self(
            strtolower($key),
            hash('sha256', $secret),
            hash_hmac('sha256', json_encode($canonicalize(['version' => 1, 'payload' => $payload, 'cart_token' => $cartToken]), JSON_THROW_ON_ERROR), $secret),
        );
    }
}
