<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Null, meaning "leave the Reply-To the install already resolves" —
        // Contact → Support email, then MAIL_REPLY_TO_*. A row appearing must
        // not change what an upgraded install's mail says.
        $this->migrator->add('communication.mail_reply_to_address', null);
        $this->migrator->add('communication.mail_reply_to_name', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('communication.mail_reply_to_address');
        $this->migrator->deleteIfExists('communication.mail_reply_to_name');
    }
};
