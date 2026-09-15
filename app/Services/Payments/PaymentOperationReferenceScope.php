<?php

namespace App\Services\Payments;

use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use Illuminate\Validation\ValidationException;

/** Requires a caller transaction so scope checks use current locking reads. */
class PaymentOperationReferenceScope
{
    public function __construct(private readonly PaymentLedgerScope $scope, private readonly AuthorizeNetReportingClient $client) {}

    public function assertCurrent(PaymentOperation $operation, GatewayAccountBinding $binding): PaymentIntent
    {
        $intent = PaymentIntent::whereKey($operation->payment_intent_id)->lockForUpdate()->firstOrFail();
        $this->scope->assertCurrent($intent);
        if ($intent->merchant_account_id !== $binding->merchant_account_id
            || $intent->merchant_account_uuid !== $binding->merchant_account_uuid
            || $intent->gateway_provider->value !== $binding->gateway_provider
            || $intent->environment->value !== $binding->environment
            || $intent->currency !== $binding->currency
            || ! hash_equals($intent->merchant_binding_fingerprint, $binding->merchant_fingerprint)) {
            throw ValidationException::withMessages(['payment' => 'Operation and gateway binding scopes do not match.']);
        }
        $merchant = MerchantAccount::whereKey($binding->merchant_account_id)->lockForUpdate()->firstOrFail();
        $this->client->assertUsable($merchant);

        return $intent;
    }
}
