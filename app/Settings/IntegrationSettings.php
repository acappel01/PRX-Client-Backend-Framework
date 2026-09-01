<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Per-tenant integration credentials. Values are stored encrypted in the
 * `settings` table (Laravel APP_KEY-encrypted via spatie/laravel-settings).
 *
 * Other integrations (NMI, Authorize.net, AWS Bedrock) will join this
 * settings group as their service classes come online.
 */
class IntegrationSettings extends Settings
{
    /*
     * --- prescribe-rx ---
     */

    public bool $prescribe_rx_enabled = false;

    /** 'sandbox' or 'production' — selects the base URL from config. */
    /**
     * WHICH INSTANCE — not whether encounters are tests.
     *
     * `sandbox` → demo.prescribe-rx.com, `production` → prescribe-rx.com.
     * Whether an encounter is a TEST is a separate switch: a mode on the
     * embed config in their admin (so real clinicians are not handed test
     * intakes), and `is_sandbox` on the API path. A production embed running
     * in sandbox mode is normal. See docs/prescribe-rx/dev.md.
     */
    public string $prescribe_rx_environment = 'sandbox';

    /**
     * Sales-organization API token issued by the prescribe-rx production
     * admin (used against either environment). Sanctum-format, includes
     * the `{id}|` prefix.
     */
    public ?string $prescribe_rx_api_token = null;

    /**
     * Optional explicit org / client UUIDs for the unified-intake payload.
     * If blank, prescribe-rx falls back to the token's authenticated org.
     */
    public ?string $prescribe_rx_sales_org_id = null;

    public ?string $prescribe_rx_client_id = null;

    /**
     * Embed code generated in the prescribe-rx admin at /admin/embed-configs.
     * Maps to a specific encounter type + tenant. Also acts as the auth
     * mechanism for the iframe — no separate token is required for embeds.
     */
    public ?string $prescribe_rx_embed_code = null;

    /**
     * The universal encounter type UUID used for all checkout submissions.
     * Set this once after linking the deployment's sales org in prescribe-rx.
     * All products and packages route to this single encounter type;
     * question visibility is handled dynamically on the frontend.
     */
    public ?string $prescribe_rx_encounter_type_id = null;

    /**
     * HMAC-SHA256 secret used to verify the X-PrescribeRx-Signature header on
     * inbound webhooks. Provisioned alongside the webhook subscription on the
     * prescribe-rx side.
     */
    public ?string $prescribe_rx_webhook_secret = null;

    public static function group(): string
    {
        return 'integrations';
    }

    /**
     * Tell spatie/laravel-settings which fields to encrypt at rest.
     *
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return [
            'prescribe_rx_api_token',
            'prescribe_rx_embed_code',
            'prescribe_rx_webhook_secret',
        ];
    }
}
