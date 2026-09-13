<?php

namespace App\Actions\Settings;

use App\Actions\Concerns\Transacts;
use App\Data\Settings\PortalSettingsData;
use App\Services\Cms\ConfigCache;
use App\Settings\PortalSettings;

class UpdatePortalSettingsAction
{
    use Transacts;

    public function __construct(private PortalSettings $settings) {}

    public function execute(PortalSettingsData $data): PortalSettings
    {
        return $this->tx(function () use ($data) {
            $this->settings->security_events_retention_days = $data->security_events_retention_days;
            $this->settings->session_idle_minutes = $data->session_idle_minutes;
            $this->settings->session_max_hours = $data->session_max_hours;
            $this->settings->two_factor_policy = $data->two_factor_policy;
            $this->settings->trusted_device_days = $data->trusted_device_days;
            $this->settings->requirement_labels = collect($data->requirement_labels)
                ->mapWithKeys(fn ($label, $slug) => [strtolower(trim((string) $slug)) => trim(strip_tags((string) $label))])
                ->filter(fn (string $label, string $slug) => $label !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $slug) === 1)
                ->all();
            $this->settings->save();

            // The portal reads the idle limit from /config to warn before it.
            ConfigCache::invalidate();

            return $this->settings;
        });
    }
}
