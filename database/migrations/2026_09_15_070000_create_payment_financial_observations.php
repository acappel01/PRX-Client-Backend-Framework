<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_financial_read_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_association_scope_id')->constrained('payment_association_scopes', indexName: 'financial_scope_fk')->restrictOnDelete();
            $t->foreignId('payment_dispatch_preparation_id')->constrained('payment_dispatch_preparations', indexName: 'financial_preparation_fk')->restrictOnDelete();
            $t->char('entity_key', 64);
            $t->char('scope_fingerprint', 64);
            $t->timestamp('started_at', 6);
            $t->index(['payment_association_scope_id', 'entity_key', 'id'], 'financial_entity_request_index');
        });
        Schema::create('payment_financial_observations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_financial_read_request_id')->unique('financial_request_unique')->constrained('payment_financial_read_requests', indexName: 'financial_request_fk')->restrictOnDelete();
            $t->string('status', 40);
            $t->string('classification', 32)->nullable();
            $t->text('facts');
            $t->timestamp('recorded_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_financial_observations');
        Schema::dropIfExists('payment_financial_read_requests');
    }
};
