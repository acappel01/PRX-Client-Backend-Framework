<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('merchant_account_id')->constrained()->restrictOnDelete();
            $table->uuid('order_uuid');
            $table->uuid('customer_uuid');
            $table->uuid('merchant_account_uuid');
            $table->string('gateway_provider', 32);
            $table->string('environment', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('executor_key', 64);
            $table->char('request_fingerprint', 64);
            $table->char('merchant_binding_fingerprint', 64);
            $table->char('order_snapshot_fingerprint', 64);
            $table->timestamp('created_at');
        });
        Schema::create('payment_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_operation_id')->nullable()->constrained('payment_operations')->restrictOnDelete();
            $table->string('purpose', 16);
            $table->string('executor_key', 64);
            $table->string('state', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->char('request_fingerprint', 64);
            $table->char('uncertainty_fingerprint', 64)->nullable();
            $table->longText('uncertainty_evidence')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('uncertain_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_operations');
        Schema::dropIfExists('payment_intents');
    }
};
