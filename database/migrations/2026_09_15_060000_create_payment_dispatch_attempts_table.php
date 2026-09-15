<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_dispatch_attempts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('payment_dispatch_preparation_id')->unique('dispatch_attempt_preparation_unique');
            $t->foreign('payment_dispatch_preparation_id', 'dispatch_attempt_preparation_fk')->references('id')->on('payment_dispatch_preparations')->restrictOnDelete();
            $t->foreignId('payment_intent_id');
            $t->foreign('payment_intent_id', 'dispatch_attempt_intent_fk')->references('id')->on('payment_intents')->restrictOnDelete();
            $t->foreignId('payment_operation_id');
            $t->foreign('payment_operation_id', 'dispatch_attempt_operation_fk')->references('id')->on('payment_operations')->restrictOnDelete();
            $t->string('executor_key', 64);
            $t->string('transport_key', 64);
            $t->string('status', 32);
            $t->char('request_fingerprint', 64);
            $t->text('request_facts');
            $t->text('receipt')->nullable();
            $t->timestamp('claimed_at', 6);
            $t->timestamp('transport_started_at', 6)->nullable();
            $t->timestamp('completed_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_dispatch_attempts');
    }
};
