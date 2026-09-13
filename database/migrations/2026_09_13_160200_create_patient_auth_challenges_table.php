<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A password accepted, a second factor not yet given.
 *
 * Deliberately NOT a Sanctum token: nothing in this API enforces token
 * abilities, so a "challenge-only" token would be a full session on every
 * route. A challenge is an opaque 256-bit value the patient's client holds for
 * a few minutes, stored here only as its sha256, exchanged once for a real
 * session at `POST /patient/auth/two-factor`.
 *
 * `attempts` is counted by conditional UPDATE before a code is checked; the
 * last allowed failure sets `voided_at`, and the password must be entered
 * again. `purpose` leaves room for a step-up challenge later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 16)->default('login');
            $table->char('token_hash', 64)->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            // DATETIME, not TIMESTAMP: see patient_email_tokens.
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->string('device_name')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['patient_id', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_auth_challenges');
    }
};
