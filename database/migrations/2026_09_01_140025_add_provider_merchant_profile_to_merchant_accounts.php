<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The telehealth provider's own id for this merchant account.
 *
 * WHY IT IS REQUIRED FOR ANYTHING BEYOND A ONE-OFF CHARGE. When we take a card
 * on the storefront and hand the result to the provider, they must attach it to
 * the SAME merchant account on their side — that is their stated prerequisite
 * for linking a vaulted card, and they validate it at intake finalisation. Only
 * then can a stored CIM profile drive subscriptions, follow-ups and later
 * captures over there.
 *
 * So this is not decoration on the row: without it, a vaulted card is ours
 * alone, and the provider cannot rebill a subscription it is meant to own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_accounts', function (Blueprint $table): void {
            $table->string('provider_merchant_profile_id')->nullable()->after('gateway_endpoint_url');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_accounts', function (Blueprint $table): void {
            $table->dropColumn('provider_merchant_profile_id');
        });
    }
};
