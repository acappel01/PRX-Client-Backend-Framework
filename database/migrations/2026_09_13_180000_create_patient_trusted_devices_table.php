<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browsers a patient chose to trust after a two-step sign-in ("trust this
 * browser"). A trusted browser skips the CODE at the next sign-in — never the
 * password.
 *
 * ONLY THE HASH IS STORED: the plain 256-bit token lives in the portal's httpOnly
 * cookie. `uuid` is the public handle a patient revokes by, so the hash never
 * leaves this table. `label` is derived server-side from the user agent at trust
 * time ("Safari on iPhone"), never typed by anyone.
 *
 * Revoked (not deleted) so the history reads; pruned once long dead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            // DATETIME, not TIMESTAMP: see patient_email_tokens.
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['patient_id', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_trusted_devices');
    }
};
