<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own slug for a product class, alongside their UUID.
 *
 * Same reasoning as the product-type slug: their intake and embed both accept
 * `product_class_id` OR `product_class_slug`, and a UUID is specific to one of
 * their environments — an id captured against sandbox resolves to nothing
 * against production, silently. The slug is the identifier likelier to survive
 * that switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_classes', function (Blueprint $table): void {
            $table->string('provider_product_class_slug')->nullable()->after('provider_product_class_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_classes', function (Blueprint $table): void {
            $table->dropColumn('provider_product_class_slug');
        });
    }
};
