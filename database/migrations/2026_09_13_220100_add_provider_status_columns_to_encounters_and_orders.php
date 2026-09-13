<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own status vocabulary, kept beside our coarse `status`.
 *
 * A provider has far more states than the handful the admin filters on
 * (prescribe-rx: 29 encounter statuses, and integer-coded workflow, payment
 * and shipping statuses on an order). Mapping down loses information, so the
 * provider's value is stored as-is and the coarse `status` is derived from it.
 * An unmapped value then leaves `status` alone instead of guessing.
 *
 * `provider_status_at` is when the PROVIDER says the change happened. Events
 * can arrive out of order (retries, replays), so an older event never
 * overwrites a newer one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table) {
            $table->string('provider_status', 64)->nullable()->after('status');
            $table->dateTime('provider_status_at')->nullable()->after('provider_status');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('provider_workflow_status', 64)->nullable()->after('status');
            $table->string('provider_payment_status', 64)->nullable()->after('provider_workflow_status');
            $table->string('provider_shipping_status', 64)->nullable()->after('provider_payment_status');
            $table->dateTime('provider_status_at')->nullable()->after('provider_shipping_status');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table) {
            $table->dropColumn(['provider_status', 'provider_status_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['provider_workflow_status', 'provider_payment_status', 'provider_shipping_status', 'provider_status_at']);
        });
    }
};
