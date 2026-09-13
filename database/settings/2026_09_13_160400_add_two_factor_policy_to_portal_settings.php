<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // 'off' | 'optional' | 'required'. Off on install: an operator turns it
        // on under Settings → Patient portal once they have tried it.
        $this->migrator->add('portal.two_factor_policy', 'off');
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('portal.two_factor_policy');
    }
};
