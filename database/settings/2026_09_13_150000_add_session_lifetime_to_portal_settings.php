<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // 30 minutes idle, 12 hours absolute — the operator's choice for the
        // first install (2026-09-13), and NIST 800-63B rev 3 AAL2. Before this,
        // a patient session never ended.
        $this->migrator->add('portal.session_idle_minutes', 30);
        $this->migrator->add('portal.session_max_hours', 12);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('portal.session_idle_minutes');
        $this->migrator->deleteIfExists('portal.session_max_hours');
    }
};
