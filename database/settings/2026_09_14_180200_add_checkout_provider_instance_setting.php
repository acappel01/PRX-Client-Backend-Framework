<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('integrations.prescribe_rx_provider_instance_key', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('integrations.prescribe_rx_provider_instance_key');
    }
};
