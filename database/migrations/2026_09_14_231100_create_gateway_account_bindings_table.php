<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_account_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_account_id')->unique()->constrained('merchant_accounts')->restrictOnDelete();
            $table->uuid('merchant_account_uuid');
            $table->string('gateway_provider', 32);
            $table->string('environment', 16);
            $table->string('gateway_account_id', 32);
            $table->string('currency', 3);
            $table->char('canonical_account_key', 64)->index();
            $table->char('merchant_fingerprint', 64);
            $table->text('provider_mapping')->nullable();
            $table->timestamp('verified_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_account_bindings');
    }
};
