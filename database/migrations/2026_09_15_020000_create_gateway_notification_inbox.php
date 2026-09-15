<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorize_net_receivers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gateway_account_binding_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->char('canonical_account_key', 64);
            $table->char('configuration_key', 64)->unique();
            $table->text('webhook_id');
            $table->string('key_version', 64);
            $table->string('signature_contract', 64);
            $table->text('signature_key');
            $table->timestamp('configured_at', 6);
        });
        Schema::create('gateway_notification_inboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authorize_net_receiver_id')->constrained()->restrictOnDelete();
            $table->char('canonical_account_key', 64);
            $table->string('environment', 16);
            $table->char('notification_key', 64);
            $table->char('raw_body_digest', 64);
            $table->text('notification');
            $table->string('state', 32)->default('received_inactive');
            $table->timestamp('received_at', 6);
            $table->unique(['canonical_account_key', 'environment', 'notification_key'], 'gateway_notification_identity');
        });
        Schema::create('gateway_notification_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gateway_notification_inbox_id')->constrained('gateway_notification_inboxes', indexName: 'notification_conflict_inbox_fk')->restrictOnDelete();
            $table->foreignId('authorize_net_receiver_id')->constrained()->restrictOnDelete();
            $table->char('raw_body_digest', 64);
            $table->text('notification');
            $table->timestamp('recorded_at', 6);
            $table->unique(['gateway_notification_inbox_id', 'raw_body_digest'], 'gateway_notification_conflict_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_notification_conflicts');
        Schema::dropIfExists('gateway_notification_inboxes');
        Schema::dropIfExists('authorize_net_receivers');
    }
};
