<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // What a patient reads for each item a held visit still needs, keyed by
        // the provider's requirement slug ("id_front" => "Front of your ID").
        // Empty: the provider's own label is shown until an operator writes one.
        $this->migrator->add('portal.requirement_labels', []);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('portal.requirement_labels');
    }
};
