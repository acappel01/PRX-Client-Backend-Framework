<?php

namespace App\Services\Payments;

use App\Data\Payments\AuthorizeNetTransactionRead;
use App\Models\Payments\GatewayAccountBinding;

/** Explicit policy inference; never a per-transaction currency field or real financial effect. */
class AuthorizeNetCurrencyAuthority
{
    public function assess(GatewayAccountBinding $binding, AuthorizeNetTransactionRead $read): array
    {
        $base = ['currency' => $read->currency, 'currency_qualified' => false,
            'authority' => 'current_merchant_configuration', 'transaction_currency_observed' => false];
        if ($binding->canonical_account_key !== $read->canonical_account_key || $binding->currency !== $read->currency
            || $binding->gateway_provider !== 'authorize_net') {
            return $base + ['reason' => 'account_scope_mismatch'];
        }
        if ($binding->environment !== 'sandbox') {
            return $base + ['reason' => 'production_policy_scope_unqualified'];
        }
        if ($read->payment_rail !== 'credit_card' || ! in_array($read->currency, AuthorizeNetReportingClient::CURRENCIES, true)) {
            return $base + ['reason' => 'payment_rail_or_currency_unqualified'];
        }

        return ['currency' => $read->currency, 'currency_qualified' => true,
            'authority' => 'authorize_net_fixed_sandbox_currency_v1', 'transaction_currency_observed' => false,
            'reason' => 'provider_account_policy_inference'];
    }
}
