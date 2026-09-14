<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('successor_cart_id')->nullable()->constrained('carts')->restrictOnDelete();
        });
        Schema::create('checkout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cart_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unique(['cart_id', 'lead_id']);
            $table->string('status', 32)->index();
            $table->string('provider_idempotency_key')->unique();
            $table->char('request_fingerprint', 64);
            $table->char('answers_fingerprint', 64);
            $table->char('cart_fingerprint', 64);
            $table->string('provider_environment', 32);
            $table->string('provider_encounter_type_id')->nullable();
            $table->text('result')->nullable();
            $table->text('provider_receipt')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_attempts');
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('successor_cart_id');
        });
    }
};
