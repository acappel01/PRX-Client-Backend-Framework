<?php

namespace App\Actions\Settings;

use App\Data\Settings\BillingSettingsData;
use App\Services\Cms\ConfigCache;
use App\Settings\BillingSettings;

class UpdateBillingSettingsAction
{
    public function __construct(private BillingSettings $settings) {}

    public function execute(BillingSettingsData $data): BillingSettings
    {
        $this->settings->checkout_path = $data->checkout_path;
        $this->settings->payment_collector = $data->payment_collector;
        $this->settings->upsells_enabled = $data->upsells_enabled;
        $this->settings->upsells_limit = $data->upsells_limit;
        $this->settings->save();

        // Invalidates BOTH caches between here and a visitor: this app's
        // own config entry and the decoupled frontend's fetch cache.
        // Clearing only the first left an edit invisible for the whole
        // ISR window — see ConfigCache.
        ConfigCache::invalidate();

        return $this->settings;
    }
}
