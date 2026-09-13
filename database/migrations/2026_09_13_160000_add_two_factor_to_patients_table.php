<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step verification (TOTP) for patient accounts.
 *
 * `two_factor_secret` / `two_factor_pending_secret` hold base32 secrets under
 * Eloquent's `encrypted` cast (text, because ciphertext is long). A pending
 * secret is one being set up and not yet confirmed with a code; it never
 * replaces a confirmed one until it is.
 *
 * `two_factor_last_timestep` is replay protection: the TOTP time step last
 * accepted. A code is accepted only by a conditional UPDATE that moves it
 * forward, so the same code cannot sign in twice, even concurrently.
 *
 * Every column here must be in `Patient::$hidden` — workflow payloads copy the
 * visible attributes into the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_pending_secret')->nullable();
            $table->dateTime('two_factor_pending_at')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_pending_secret',
                'two_factor_pending_at',
                'two_factor_confirmed_at',
                'two_factor_last_timestep',
            ]);
        });
    }
};
