<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clinical provider's own identifiers for a linked patient, so support can
 * find our account from the number the provider's staff quote.
 *
 * `prx_patient_id` (the provider's user id) existed but was never written;
 * `prx_patient_number` is the chart's human-readable patient number. Both are
 * identifiers, not demographics: name, date of birth and phone are read LIVE
 * from the provider and not copied here (operator decision 2026-09-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('prx_patient_number', 64)->nullable()->after('prx_patient_id');
            $table->index('prx_patient_number');
            $table->index('prx_patient_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['prx_patient_number']);
            $table->dropIndex(['prx_patient_id']);
            $table->dropColumn('prx_patient_number');
        });
    }
};
