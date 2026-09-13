<?php

namespace App\Actions\Settings;

use App\Actions\Concerns\Transacts;
use App\Data\Settings\PortalSettingsData;
use App\Settings\PortalSettings;

class UpdatePortalSettingsAction
{
    use Transacts;

    public function __construct(private PortalSettings $settings) {}

    public function execute(PortalSettingsData $data): PortalSettings
    {
        return $this->tx(function () use ($data) {
            $this->settings->security_events_retention_days = $data->security_events_retention_days;
            $this->settings->save();

            return $this->settings;
        });
    }
}
