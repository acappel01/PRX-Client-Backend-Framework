<?php

namespace App\Data\Payments;

/** Only opaque notification/transaction references cross the verifier boundary. */
final readonly class VerifiedGatewayNotification
{
    public function __construct(public string $notification_id, public string $event_type, public string $event_date, public string $webhook_id, public string $entity_name, public string $entity_id, public ?string $merchant_reference_id) {}

    public function projection(): array
    {
        return get_object_vars($this);
    }
}
