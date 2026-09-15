<?php

namespace App\Actions\Payments;

use App\Data\Payments\AuthorizeNetReceiverData;
use App\Models\Payments\AuthorizeNetReceiver;
use App\Models\Payments\GatewayAccountBinding;
use App\Services\Payments\AuthorizeNetNotificationVerifier;
use App\Services\Payments\AuthorizeNetReceiverScope;
use App\Services\Payments\PaymentLedgerScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal configuration only. Does not create a provider webhook or expose a local route. */
class ConfigureAuthorizeNetReceiverAction
{
    public function __construct(private readonly AuthorizeNetReceiverScope $scope, private readonly PaymentLedgerScope $fingerprints) {}

    public function execute(AuthorizeNetReceiverData $data): AuthorizeNetReceiver
    {
        if ($data->binding_id < 1 || preg_match(AuthorizeNetNotificationVerifier::UUID, $data->webhook_id) !== 1
            || preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $data->key_version) !== 1) {
            $this->scope->reject();
        }

        return DB::transaction(function () use ($data): AuthorizeNetReceiver {
            $binding = GatewayAccountBinding::query()->find($data->binding_id);
            if ($binding === null || $binding->environment !== $data->environment->value) {
                $this->scope->reject();
            }
            $merchant = $this->scope->current($binding);
            $webhook = strtolower($data->webhook_id);
            $identity = $this->fingerprints->fingerprint(['receiver-v1', $binding->id, $binding->environment, $webhook, $data->key_version]);
            $prior = AuthorizeNetReceiver::query()->where('configuration_key', $identity)->lockForUpdate()->first();
            if ($prior !== null) {
                return $prior;
            }

            return AuthorizeNetReceiver::create([
                'uuid' => (string) Str::uuid(), 'gateway_account_binding_id' => $binding->id,
                'environment' => $binding->environment, 'canonical_account_key' => $binding->canonical_account_key,
                'configuration_key' => $identity, 'webhook_id' => $webhook, 'key_version' => $data->key_version,
                'signature_contract' => AuthorizeNetNotificationVerifier::CONTRACT,
                'signature_key' => $merchant->authnet_signature_key, 'configured_at' => now()->utc(),
            ]);
        }, 3);
    }
}
