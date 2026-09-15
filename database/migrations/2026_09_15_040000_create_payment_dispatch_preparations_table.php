<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_dispatch_preparations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_operation_reference_id')->unique('dispatch_reference_unique');
            $table->foreign('payment_operation_reference_id', 'dispatch_reference_fk')->references('id')->on('payment_operation_references')->restrictOnDelete();
            $table->foreignId('payment_operation_id')->unique('dispatch_operation_unique');
            $table->foreign('payment_operation_id', 'dispatch_operation_fk')->references('id')->on('payment_operations')->restrictOnDelete();
            $table->foreignId('gateway_account_binding_id');
            $table->foreign('gateway_account_binding_id', 'dispatch_binding_fk')->references('id')->on('gateway_account_bindings')->restrictOnDelete();
            $table->char('canonical_account_key', 64)->index('dispatch_account_index');
            $table->string('environment', 16);
            $table->string('executor_key', 64);
            $table->char('request_fingerprint', 64);
            $table->text('prepared_scope');
            $table->string('state', 32);
            $table->timestamp('prepared_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_dispatch_preparations');
    }
};
