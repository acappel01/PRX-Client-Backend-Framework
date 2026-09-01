<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to this visitor's card, before they reach the clinical intake.
 *
 * THE POINT OF RECORDING IT IS THE GATE. When this deployment collects
 * payment, a lead may not enter the clinical intake until the card has
 * actually succeeded — otherwise a declined card still produces a completed
 * encounter, a clinician spends time on it, and product ships for money that
 * was never taken. The intake endpoint refuses on anything but a successful
 * authorisation or capture.
 *
 * THE CIM PROFILE IDS ARE NOT OPTIONAL EXTRAS. The telehealth provider can only
 * reach a card we vaulted if we hand them the profile it lives in, on the same
 * merchant account. Without them there is no later capture and no subscription
 * rebill on their side — so the card is vaulted in BOTH sequences, not only the
 * authorise-then-capture one.
 *
 * No card number, expiry or CVC is stored here or anywhere else. Those are
 * exchanged for an opaque token in the browser and never reach this server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // none | authorized | captured | failed
            $table->string('payment_status', 24)->default('none')->after('checkout_path');
            $table->string('payment_gateway_provider', 32)->nullable()->after('payment_status');

            // The account the charge ran on. A token is worthless at any other
            // gateway, so which one took it is part of the record.
            $table->string('merchant_account_uuid', 36)->nullable()->after('payment_gateway_provider');

            $table->string('payment_transaction_id', 64)->nullable()->after('merchant_account_uuid');
            $table->string('payment_authorization_code', 32)->nullable()->after('payment_transaction_id');
            $table->decimal('payment_amount', 10, 2)->nullable()->after('payment_authorization_code');

            // CIM — what the provider needs to charge or rebill this card.
            $table->string('provider_customer_profile_id', 64)->nullable()->after('payment_amount');
            $table->string('provider_payment_profile_id', 64)->nullable()->after('provider_customer_profile_id');

            // Display only: what the patient sees on a receipt, and what the
            // provider's vaulted-card block expects alongside the profile ids.
            $table->string('card_brand', 24)->nullable()->after('provider_payment_profile_id');
            $table->string('card_last_four', 4)->nullable()->after('card_brand');
            $table->unsignedSmallInteger('card_exp_month')->nullable()->after('card_last_four');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');

            $table->timestamp('payment_processed_at')->nullable()->after('card_exp_year');
            $table->text('payment_failure_reason')->nullable()->after('payment_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_status', 'payment_gateway_provider', 'merchant_account_uuid',
                'payment_transaction_id', 'payment_authorization_code', 'payment_amount',
                'provider_customer_profile_id', 'provider_payment_profile_id',
                'card_brand', 'card_last_four', 'card_exp_month', 'card_exp_year',
                'payment_processed_at', 'payment_failure_reason',
            ]);
        });
    }
};
