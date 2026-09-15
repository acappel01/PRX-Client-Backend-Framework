<?php

namespace App\Actions\Payments;

use App\Data\Payments\GatewayNotificationData;
use App\Data\Payments\GatewayNotificationResult;
use App\Models\Payments\AuthorizeNetReceiver;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\GatewayNotificationConflict;
use App\Models\Payments\GatewayNotificationInbox;
use App\Services\Payments\AuthorizeNetNotificationVerifier;
use App\Services\Payments\AuthorizeNetReceiverScope;
use Illuminate\Support\Facades\DB;

/** Durable inactive intake only: no route, queue, HTTP, registry lookup or payment effects. */
class ReceiveAuthorizeNetNotificationAction
{
    public function __construct(private readonly AuthorizeNetReceiverScope $scope, private readonly AuthorizeNetNotificationVerifier $verifier) {}

    public function execute(GatewayNotificationData $data): GatewayNotificationResult
    {
        if (DB::transactionLevel() !== 0) {
            $this->scope->reject();
        }

        return DB::transaction(function () use ($data): GatewayNotificationResult {
            $snapshot = AuthorizeNetReceiver::query()->find($data->receiver_id);
            $binding = $snapshot === null ? null : GatewayAccountBinding::query()->find($snapshot->gateway_account_binding_id);
            if ($binding === null) {
                $this->scope->reject();
            }
            $merchant = $this->scope->current($binding);
            $receiver = AuthorizeNetReceiver::query()->whereKey($data->receiver_id)->lockForUpdate()->first();
            if ($receiver === null || $receiver->gateway_account_binding_id !== $binding->id
                || $receiver->environment !== $binding->environment || $receiver->canonical_account_key !== $binding->canonical_account_key
                || ! hash_equals($merchant->authnet_signature_key, $receiver->signature_key)) {
                $this->scope->reject();
            }
            $notification = $this->verifier->verify($receiver, $data);
            $identity = hash_hmac('sha256', "notification-v1\0".$notification->notification_id, (string) config('app.key'));
            $digest = hash_hmac('sha256', "notification-body-v1\0".$data->raw_body, (string) config('app.key'));
            $candidate = new GatewayNotificationInbox([
                'authorize_net_receiver_id' => $receiver->id, 'canonical_account_key' => $binding->canonical_account_key,
                'environment' => $binding->environment, 'notification_key' => $identity, 'raw_body_digest' => $digest,
                'notification' => $notification->projection(), 'state' => 'received_inactive', 'received_at' => now()->utc(),
            ]);
            // Unique canonical identity serializes duplicate local merchant/receiver rows too.
            $inserted = GatewayNotificationInbox::query()->insertOrIgnore($candidate->getAttributes());
            $inbox = GatewayNotificationInbox::query()->where('canonical_account_key', $binding->canonical_account_key)
                ->where('environment', $binding->environment)->where('notification_key', $identity)->lockForUpdate()->first();
            if ($inbox === null) {
                $this->scope->reject();
            }
            if (hash_equals($inbox->raw_body_digest, $digest)) {
                return new GatewayNotificationResult($inbox->id, $inserted === 1 ? 'received_inactive' : 'duplicate');
            }
            $candidate = new GatewayNotificationConflict([
                'gateway_notification_inbox_id' => $inbox->id, 'authorize_net_receiver_id' => $receiver->id,
                'raw_body_digest' => $digest, 'notification' => $notification->projection(), 'recorded_at' => now()->utc(),
            ]);
            GatewayNotificationConflict::query()->insertOrIgnore($candidate->getAttributes());
            $conflict = GatewayNotificationConflict::query()->where('gateway_notification_inbox_id', $inbox->id)
                ->where('raw_body_digest', $digest)->lockForUpdate()->first();
            if ($conflict === null) {
                $this->scope->reject();
            }

            return new GatewayNotificationResult($inbox->id, 'conflict', $conflict->id);
        }, 3);
    }
}
