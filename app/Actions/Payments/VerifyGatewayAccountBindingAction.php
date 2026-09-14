<?php

namespace App\Actions\Payments;

use App\Data\Payments\GatewayAccountBindingData;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentLedgerScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Trusted internal caller must authorize the selected account and supply explicit mapping facts. */
class VerifyGatewayAccountBindingAction
{
    public function __construct(private readonly AuthorizeNetReportingClient $client, private readonly PaymentLedgerScope $scope) {}

    public function execute(GatewayAccountBindingData $data): GatewayAccountBinding
    {
        if (DB::transactionLevel() !== 0) {
            $this->client->reject();
        }
        Validator::make($data->toArray(), [
            'merchant_account_id' => ['required', 'integer', 'min:1'],
            'expected_gateway_account_id' => ['required', 'regex:/\A[1-9][0-9]{0,31}\z/'],
            'expected_currency' => ['required', 'in:'.implode(',', AuthorizeNetReportingClient::CURRENCIES)],
            'expected_provider_mapping' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $merchant = MerchantAccount::find($data->merchant_account_id);
        if ($merchant === null || $merchant->environment !== $data->environment
            || $merchant->provider_merchant_profile_id !== $data->expected_provider_mapping) {
            $this->client->reject();
        }
        $fingerprint = $this->scope->merchantFingerprint($merchant);
        $prior = GatewayAccountBinding::where('merchant_account_id', $merchant->id)->first();
        if ($prior !== null && (! hash_equals($prior->merchant_fingerprint, $fingerprint)
            || $prior->gateway_account_id !== $data->expected_gateway_account_id || $prior->currency !== $data->expected_currency)) {
            $this->client->reject();
        }
        // No remote IO inside a retryable DB transaction.
        $read = $this->client->merchant($merchant);
        if ($read->gateway_account_id !== $data->expected_gateway_account_id || $read->currency !== $data->expected_currency) {
            $this->client->reject();
        }

        return DB::transaction(function () use ($merchant, $fingerprint, $read): GatewayAccountBinding {
            $current = MerchantAccount::whereKey($merchant->id)->lockForUpdate()->first();
            if ($current === null || ! $current->is_active || ! hash_equals($fingerprint, $this->scope->merchantFingerprint($current))) {
                $this->client->reject();
            }
            $existing = GatewayAccountBinding::where('merchant_account_id', $merchant->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->merchant_fingerprint, $fingerprint)
                    || $existing->gateway_account_id !== $read->gateway_account_id || $existing->currency !== $read->currency) {
                    $this->client->reject();
                }

                return $existing;
            }

            return GatewayAccountBinding::create([
                'merchant_account_id' => $merchant->id, 'merchant_account_uuid' => $merchant->uuid,
                'gateway_provider' => $merchant->gateway_provider->value, 'environment' => $merchant->environment->value,
                'gateway_account_id' => $read->gateway_account_id, 'currency' => $read->currency,
                // Credentials/local row IDs are deliberately absent: verified duplicate local rows share identity.
                'canonical_account_key' => hash('sha256', implode(':', [$merchant->gateway_provider->value, $merchant->environment->value, $read->gateway_account_id])),
                'merchant_fingerprint' => $fingerprint, 'provider_mapping' => $merchant->provider_merchant_profile_id,
                'verified_at' => now(),
            ]);
        }, 3);
    }
}
