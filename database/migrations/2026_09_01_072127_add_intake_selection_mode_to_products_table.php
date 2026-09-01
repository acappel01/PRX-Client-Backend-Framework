<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product choice of which identifier a prescribe-rx intake nominates:
 * the exact product, or its product TYPE so the prescribing clinician picks
 * the variant and dose.
 *
 * Defaults to `product`, which is what every existing row was doing
 * implicitly — so this migration changes no behaviour on its own. An operator
 * opts a product into clinician-chosen dosing explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('intake_selection_mode', 32)
                ->default('product')
                ->after('provider_encounter_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('intake_selection_mode');
        });
    }
};
