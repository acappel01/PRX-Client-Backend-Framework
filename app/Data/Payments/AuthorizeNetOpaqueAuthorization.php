<?php

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

/** Ephemeral authorization from a trusted resolver; never serialized, logged or stored. */
final readonly class AuthorizeNetOpaqueAuthorization
{
    public function __construct(
        public string $request_fingerprint,
        public string $preparation_uuid,
        public string $canonical_account_key,
        public string $customer_uuid,
        public CarbonImmutable $expires_at,
        #[\SensitiveParameter] private string $opaque_value,
    ) {}

    public function value(): string
    {
        return $this->opaque_value;
    }

    public function __debugInfo(): array
    {
        return ['instrument' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Ephemeral instruments cannot be serialized.');
    }
}
