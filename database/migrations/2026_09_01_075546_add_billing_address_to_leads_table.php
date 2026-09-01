<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a separate BILLING address to leads.
 *
 * The existing unprefixed address columns (`address_line1`, `city`, `state`,
 * …) are the SHIPPING address and always have been — it is the address sent
 * to the telehealth provider, and the one whose state decides which licensed
 * clinician can be assigned. They are deliberately NOT renamed to
 * `shipping_*`: those names are part of the public `POST /leads` contract, so
 * a rename would break every existing API consumer for a naming gain.
 *
 * `billing_same_as_shipping` defaults TRUE, which is both the common case and
 * the behaviour before this migration — billing columns stay null and the
 * shipping address serves for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->boolean('billing_same_as_shipping')->default(true)->after('country');
            $table->string('billing_address_line1')->nullable()->after('billing_same_as_shipping');
            $table->string('billing_address_line2')->nullable()->after('billing_address_line1');
            $table->string('billing_city')->nullable()->after('billing_address_line2');
            $table->string('billing_state', 2)->nullable()->after('billing_city');
            $table->string('billing_postal_code', 16)->nullable()->after('billing_state');
            $table->string('billing_country', 2)->nullable()->after('billing_postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_same_as_shipping',
                'billing_address_line1',
                'billing_address_line2',
                'billing_city',
                'billing_state',
                'billing_postal_code',
                'billing_country',
            ]);
        });
    }
};
