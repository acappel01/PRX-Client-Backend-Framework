<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // How long "trust this browser" lasts, sliding with use. 0 turns the
        // choice off entirely: every two-step sign-in asks for a code.
        $this->migrator->add('portal.trusted_device_days', 30);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('portal.trusted_device_days');
    }
};
