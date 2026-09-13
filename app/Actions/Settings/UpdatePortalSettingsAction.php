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
            $this->settings->save();

            // The portal reads the idle limit from /config to warn before it.
            ConfigCache::invalidate();

            return $this->settings;
        });
    }
}
