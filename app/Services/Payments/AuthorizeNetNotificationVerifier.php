<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayNotificationData;
use App\Data\Payments\VerifiedGatewayNotification;
use App\Models\Payments\AuthorizeNetReceiver;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Historical published vector qualifies text-key arithmetic; no live receiver is activated. */
class AuthorizeNetNotificationVerifier
{
    public const CONTRACT = 'authorize-net-webhook-text-key-v1';

    public const MAX_BYTES = 65536;

    public const UUID = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    public function __construct(private readonly StrictNotificationJson $json) {}

    public function verify(AuthorizeNetReceiver $receiver, GatewayNotificationData $data): VerifiedGatewayNotification
    {
        if ($receiver->signature_contract !== self::CONTRACT || strlen($data->raw_body) < 2 || strlen($data->raw_body) > self::MAX_BYTES
            || ! in_array(strtolower($data->content_encoding ?? ''), ['', 'identity'], true)
            || ! array_is_list($data->signature_headers) || count($data->signature_headers) !== 1
            || ! is_string($data->signature_headers[0])
            || preg_match('/\Asha512=([0-9a-f]{128})\z/i', $data->signature_headers[0], $matches) !== 1) {
            $this->reject();
        }
        $key = $receiver->signature_key;
        if (! is_string($key) || preg_match('/\A[0-9a-fA-F]{128}\z/', $key) !== 1
            || ! hash_equals(hash_hmac('sha512', $data->raw_body, $key), strtolower($matches[1]))) {
            $this->reject();
        }
        $value = $this->json->decode($data->raw_body);
        $payload = $value->payload ?? null;
        if (! $this->uuid($value->notificationId ?? null) || ! $this->uuid($value->webhookId ?? null)
            || strtolower($value->webhookId) !== $receiver->webhook_id
            || ! is_string($value->eventType ?? null) || strlen($value->eventType) > 128
            || preg_match('/\Anet\.authorize\.payment\.[A-Za-z]+(?:\.[A-Za-z]+)*\z/', $value->eventType) !== 1
            || ! $this->date($value->eventDate ?? null) || ! $payload instanceof stdClass
            || ($payload->entityName ?? null) !== 'transaction' || ! is_string($payload->id ?? null)
            || preg_match('/\A(?:0|[1-9][0-9]{0,31})\z/', $payload->id) !== 1) {
            $this->reject();
        }
        $reference = $payload->merchantReferenceId ?? null;
        if ($reference !== null && (! is_string($reference) || preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $reference) !== 1)) {
            $this->reject();
        }

        return new VerifiedGatewayNotification(strtolower($value->notificationId), $value->eventType, $value->eventDate,
            strtolower($value->webhookId), 'transaction', $payload->id, $reference);
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,7})?Z\z/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['notification' => 'The notification could not be authenticated for this receiver.']);
    }
}
