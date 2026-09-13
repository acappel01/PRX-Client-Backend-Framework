<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this session last proved a second factor.
 *
 * Set on the session a two-step sign-in mints. It is the primitive a later
 * step-up check reads ("a code within the last N minutes before a clinical
 * write") — a timestamp rather than a token ability, because recency is the
 * point and an ability cannot carry a time. Nothing reads it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dateTime('two_factor_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('two_factor_verified_at');
        });
    }
};
