<?php

namespace App\Services\Checkout;

use App\Actions\Exceptions\ActionException;
use App\Models\Commerce\CheckoutAttempt;
use App\Models\ProviderInstance;
use App\Settings\IntegrationSettings;

/** Binds an explicit routing scope; no token-derived or historical settings inference. */
class CheckoutProviderBinding
{
    public function configured(IntegrationSettings $settings): ProviderInstance
    {
        $instance = ProviderInstance::where('key', $settings->prescribe_rx_provider_instance_key)->first();
        $this->assertMatches($instance, $settings->prescribe_rx_environment,
            $settings->prescribe_rx_client_id, $settings->prescribe_rx_sales_org_id);

        return $instance;
    }

    public function recorded(CheckoutAttempt $attempt, string $expectedKey): ProviderInstance
    {
        $instance = $attempt->provider_instance_id === null ? null : ProviderInstance::find($attempt->provider_instance_id);
        if ($instance === null || ! hash_equals($instance->key, $expectedKey)) {
            throw ActionException::failed('The recorded provider binding cannot be verified.', 409);
        }
        $this->assertMatches($instance, $attempt->provider_environment, $attempt->provider_client_id, $attempt->provider_sales_org_id);
        $kind = filled($attempt->provider_client_id) ? 'client' : 'sales_organization';
        if ($attempt->provider_tenant_kind !== $kind) {
            throw ActionException::failed('The recorded provider binding cannot be verified.', 409);
        }

        return $instance;
    }

    private function assertMatches(?ProviderInstance $instance, string $environment, ?string $clientId, ?string $salesOrgId): void
    {
        $kind = filled($clientId) ? 'client' : 'sales_organization';
        $accountId = filled($clientId) ? $clientId : $salesOrgId;
        if ($instance === null || ! in_array($environment, ['sandbox', 'production'], true)
            || ! is_string($accountId) || trim($accountId) === '' || trim($accountId) !== $accountId
            || $instance->provider !== 'prescribe_rx' || $instance->environment !== $environment
            || $instance->account_type !== $kind || $instance->external_account_id !== $accountId) {
            throw ActionException::failed('We cannot verify the configured checkout provider. Please contact support.', 503);
        }
        // Both values are transmitted when supplied: reject malformed optional routing too.
        foreach ([$clientId, $salesOrgId] as $value) {
            if ($value !== null && ($value === '' || trim($value) !== $value)) {
                throw ActionException::failed('We cannot verify the configured checkout provider. Please contact support.', 503);
            }
        }
    }
}
