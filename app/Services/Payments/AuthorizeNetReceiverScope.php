<?php

namespace App\Services\Payments;

use App\Enums\Payments\GatewayProvider;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use Illuminate\Validation\ValidationException;

/** Current reads under the caller's transaction, with merchant-before-binding lock order. */
class AuthorizeNetReceiverScope
{
    public function __construct(private readonly PaymentLedgerScope $scope) {}

    public function current(GatewayAccountBinding $snapshot): MerchantAccount
    {
        $merchant = MerchantAccount::query()->whereKey($snapshot->merchant_account_id)->lockForUpdate()->first();
        $binding = GatewayAccountBinding::query()->whereKey($snapshot->id)->lockForUpdate()->first();
        if ($merchant === null || $binding === null || ! $merchant->is_active
            || $merchant->gateway_provider !== GatewayProvider::AuthorizeNet
            || $merchant->uuid !== $binding->merchant_account_uuid || $merchant->environment->value !== $binding->environment
            || ! hash_equals($binding->merchant_fingerprint, $this->scope->merchantFingerprint($merchant))
            || ! is_string($merchant->authnet_signature_key)
            || preg_match('/\A[0-9a-fA-F]{128}\z/', $merchant->authnet_signature_key) !== 1) {
            $this->reject();
        }

        return $merchant;
    }

    public function reject(): never
    {
        throw ValidationException::withMessages(['notification' => 'The configured notification receiver is unavailable or has changed.']);
    }
}
