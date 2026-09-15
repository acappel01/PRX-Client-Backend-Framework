<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_operation_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_operation_id')->unique()->constrained('payment_operations')->restrictOnDelete();
            $table->foreignId('gateway_account_binding_id')->constrained('gateway_account_bindings')->restrictOnDelete();
            $table->char('canonical_account_key', 64);
            $table->string('environment', 16);
            $table->char('reference', 20);
            $table->timestamp('created_at', 6);
            $table->unique(['canonical_account_key', 'reference'], 'payment_reference_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_operation_references');
    }
};
