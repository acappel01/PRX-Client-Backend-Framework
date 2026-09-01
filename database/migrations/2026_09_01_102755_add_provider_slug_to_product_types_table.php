<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own slug for a product type, alongside their UUID.
 *
 * WHY BOTH. Their intake accepts `products[].product_type_id` OR
 * `products[].product_type_slug`, and the two fail differently: a UUID is
 * environment-specific, so an id captured against sandbox resolves to NOTHING
 * against production — and it fails silently, reporting "no products found"
 * exactly as a malformed payload would. A slug is far likelier to survive that
 * switch.
 *
 * This install has already been bitten: two of three mapped products pointed
 * at ids absent from the production catalogue. The slug is the resilient
 * identifier and the fallback when no id is mapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_types', function (Blueprint $table): void {
            $table->string('provider_product_type_slug')->nullable()->after('provider_product_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_types', function (Blueprint $table): void {
            $table->dropColumn('provider_product_type_slug');
        });
    }
};
