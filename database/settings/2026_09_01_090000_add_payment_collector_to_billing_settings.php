<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Defaults to the provider collecting, which is what every install
        // does today — the embed takes payment and nothing is charged here.
        // Changing it to 'storefront' requires a working merchant account, so
        // it must be an explicit operator decision rather than a default.
        //
        // A settings PROPERTY without a row here loads fine from the class
        // default and then throws on every save. That has bitten this repo
        // before; the row is the point of this file.
        $this->migrator->add('billing.payment_collector', 'provider');
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('billing.payment_collector');
    }
};
