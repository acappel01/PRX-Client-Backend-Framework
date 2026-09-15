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
        if ($binding->environment === 'production') {
            // Exact documented North American names only; no guessed processor-ID mapping.
            // https://support.authorize.net/knowledgebase/article/000001210/en-us
            $supported = [
                'Chase Paymentech - Tampa Processing Platform' => ['USD', 'CAD'],
                'Elavon' => ['USD', 'CAD'],
                'First Data Omaha (FDCO/FDR-First Data Resources)' => ['USD'],
                'First Data (Nashville/FDCN/FDMS)' => ['USD', 'CAD'],
                'Global Payments North America (GPS/Global Payments)' => ['USD', 'CAD'],
                'Heartland Payment Systems' => ['USD'],
                'NAB EPX' => ['USD'],
                'TSYS Acquiring Solutions (TSYS/Vital)' => ['USD'],
                'Worldpay (Vantiv Core)' => ['USD'],
            ];
            $processors = $read->account_processors;
            $processor = count($processors) === 1 ? ($processors[0] ?? []) : [];
            if ($read->payment_rail !== 'credit_card' || ! is_string($processor['id'] ?? null)
                || ! preg_match('/\A[1-9][0-9]{0,9}\z/', $processor['id']) || (int) $processor['id'] > 2147483647
                || ! in_array($read->currency, $supported[$processor['name'] ?? ''] ?? [], true)) {
                return $base + ['reason' => 'production_policy_scope_unqualified'];
            }

            return ['currency' => $read->currency, 'currency_qualified' => true,
                'authority' => 'authorize_net_fixed_north_american_account_currency_v1',
                'transaction_currency_observed' => false, 'reason' => 'provider_account_policy_inference',
                'processor' => $processor];
        }
        if ($binding->environment !== 'sandbox') {
            return $base + ['reason' => 'environment_unqualified'];
        }
        if ($read->payment_rail !== 'credit_card' || ! in_array($read->currency, AuthorizeNetReportingClient::CURRENCIES, true)) {
            return $base + ['reason' => 'payment_rail_or_currency_unqualified'];
        }

        return ['currency' => $read->currency, 'currency_qualified' => true,
            'authority' => 'authorize_net_fixed_sandbox_currency_v1', 'transaction_currency_observed' => false,
            'reason' => 'provider_account_policy_inference'];
    }
}
