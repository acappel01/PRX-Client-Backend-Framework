<?php

/*
|--------------------------------------------------------------------------
| PrescribeRx Telehealth Unified API
|--------------------------------------------------------------------------
|
| Static configuration for the prescribe-rx integration: base URLs per
| environment, HTTP timeouts, retry policy, and stub-mode toggle. The
| per-tenant credentials (API token, environment selection) are stored
| in IntegrationSettings (spatie/laravel-settings, encrypted).
|
*/

return [
    /*
     * Base URL per environment. Both already include `/api/v1`.
     * Override via PRESCRIBE_RX_SANDBOX_URL / PRESCRIBE_RX_PRODUCTION_URL
     * if the client moves to a custom domain.
     */
    'urls' => [
        'sandbox' => env('PRESCRIBE_RX_SANDBOX_URL', 'https://demo.prescribe-rx.com/api/v1'),
        'production' => env('PRESCRIBE_RX_PRODUCTION_URL', 'https://prescribe-rx.com/api/v1'),
    ],

    /*
     * HTTP timeouts and retry policy. The unified intake is a heavyweight
     * call (creates patient + encounter + answers + products in one shot)
     * so connect timeout is short but request timeout is generous.
     */
    'http' => [
        'connect_timeout' => env('PRESCRIBE_RX_CONNECT_TIMEOUT', 5),
        'request_timeout' => env('PRESCRIBE_RX_REQUEST_TIMEOUT', 30),
        'retry_times' => env('PRESCRIBE_RX_RETRY_TIMES', 2),
        'retry_sleep_ms' => env('PRESCRIBE_RX_RETRY_SLEEP_MS', 200),
    ],

    /*
     * When true, the Client returns canned responses without making real
     * HTTP calls. Useful for local dev before a sales-org token is issued
     * and for CI/test environments.
     */
    'stub' => env('PRESCRIBE_RX_STUB', false),

    /*
     * Default headers sent on every request. The `Accept: application/json`
     * header is mandatory per the API contract — without it the server
     * returns a 302 redirect to the web login page on auth failure instead
     * of a JSON 401, masking the real error.
     */
    'default_headers' => [
        'Accept' => 'application/json',
    ],

    /*
     * Embed SDK behavior. The exact step slugs are encounter-type-specific
     * and live in the prescribe-rx admin. Unknown slugs are silently
     * ignored by the SDK, so it's safe to list multiple variants — the
     * embed will skip whichever ones match its actual schema.
     *
     * To find the true slugs for an encounter type:
     *   1) Open https://demo.prescribe-rx.com/admin/encounter/types/{id}/edit
     *      — each step shows its slug; OR
     *   2) Hit GET /api/v1/telehealth/encounter-types/{id}/schema and read
     *      the `steps[*].slug` field
     *
     * We pre-populate the most common slugs for personal-info and
     * product-selection since those are what /checkout always prefills.
     * Add encounter-type-specific slugs here as you find them.
     */
    'embed' => [
        'skip_steps' => [
            // Confirmed slugs for the current default encounter type
            // (019df619-5f51-71d0-90fc-a38d8db60163). When adding a new
            // encounter type, add its skip-able slugs here — unknown
            // slugs are silently ignored by the SDK so it's safe to
            // accumulate them.
            'product-selection',
            'demographics',

            // The storefront collects name / contact / DOB / sex / address
            // before the handoff, so the provider's personal-information step
            // has nothing left to ask. Several slug variants are listed
            // because they differ by encounter type and the SDK silently
            // ignores ones it does not recognise.
            'personal-information',
            'personal-info',
        ],

        /**
         * Skipped ONLY when this side collects payment
         * (`BillingSettings::collectsPaymentOnSite()`). Kept separate from the
         * always-skip list above because whether the provider takes payment is
         * an operator decision, not a property of the encounter type.
         */
        'payment_step_slugs' => [
            'checkout',
            'payment',
        ],
    ],
];
