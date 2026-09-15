<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_token_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('payment_dispatch_preparation_id')->unique('checkout_grant_preparation_unique');
            $table->foreign('payment_dispatch_preparation_id', 'checkout_grant_preparation_fk')->references('id')->on('payment_dispatch_preparations')->restrictOnDelete();
            $table->char('token_fingerprint', 64)->unique('checkout_grant_token_unique');
            $table->char('scope_fingerprint', 64);
            $table->text('scope');
            $table->timestamp('issued_at', 6);
            $table->timestamp('expires_at', 6);
        });
        Schema::create('checkout_token_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('checkout_token_grant_id')->unique('checkout_consumption_grant_unique');
            $table->foreign('checkout_token_grant_id', 'checkout_consumption_grant_fk')->references('id')->on('checkout_token_grants')->restrictOnDelete();
            $table->unsignedBigInteger('payment_dispatch_attempt_id')->unique('checkout_consumption_attempt_unique');
            $table->foreign('payment_dispatch_attempt_id', 'checkout_consumption_attempt_fk')->references('id')->on('payment_dispatch_attempts')->restrictOnDelete();
            $table->timestamp('consumed_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_token_consumptions');
        Schema::dropIfExists('checkout_token_grants');
    }
};
