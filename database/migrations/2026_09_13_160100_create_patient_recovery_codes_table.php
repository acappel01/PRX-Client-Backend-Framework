<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time recovery codes for two-step verification.
 *
 * ONLY THE HASH IS STORED — sha256, not bcrypt, for the reason the email-link
 * tokens use it: each code carries 80 bits of entropy, which is out of reach of
 * an offline search whatever the hash, while bcrypt on every code for every
 * attempt would be a CPU-exhaustion lever on an endpoint anyone holding the
 * password can reach. Consumed by a conditional UPDATE on `used_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->dateTime('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['patient_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_recovery_codes');
    }
};
