<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_uncertainty_resolutions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_operation_id')->unique('uncertainty_resolution_operation_unique')->constrained('payment_operations', indexName: 'uncertainty_resolution_operation_fk')->restrictOnDelete();
            $t->foreignId('payment_dispatch_attempt_id')->constrained('payment_dispatch_attempts', indexName: 'uncertainty_resolution_attempt_fk')->restrictOnDelete();
            $t->foreignId('payment_financial_read_request_id')->constrained('payment_financial_read_requests', indexName: 'uncertainty_resolution_request_fk')->restrictOnDelete();
            $t->foreignId('payment_financial_observation_id')->constrained('payment_financial_observations', indexName: 'uncertainty_resolution_observation_fk')->restrictOnDelete();
            $t->char('uncertainty_fingerprint', 64);
            $t->char('evidence_fingerprint', 64);
            $t->text('evidence');
            $t->timestamp('resolved_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_uncertainty_resolutions');
    }
};
