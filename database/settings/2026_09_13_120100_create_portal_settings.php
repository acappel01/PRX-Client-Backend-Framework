<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Two years: the operator's choice for the first install (2026-09-13).
        // Editable under Settings → Patient portal.
        $this->migrator->add('portal.security_events_retention_days', 730);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('portal.security_events_retention_days');
    }
};
