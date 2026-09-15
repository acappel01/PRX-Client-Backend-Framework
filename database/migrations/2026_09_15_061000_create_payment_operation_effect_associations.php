<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_operation_effect_associations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_association_scope_id')->constrained('payment_association_scopes', indexName: 'effect_scope_fk')->restrictOnDelete();
            $table->foreignId('payment_dispatch_preparation_id')->constrained('payment_dispatch_preparations', indexName: 'effect_preparation_fk')->restrictOnDelete();
            $table->foreignId('parent_preparation_id')->constrained('payment_dispatch_preparations', indexName: 'effect_parent_preparation_fk')->restrictOnDelete();
            $table->foreignId('payment_operation_id')->constrained('payment_operations', indexName: 'effect_operation_fk')->restrictOnDelete();
            $table->foreignId('payment_dispatch_attempt_id')->constrained('payment_dispatch_attempts', indexName: 'effect_dispatch_attempt_fk')->restrictOnDelete();
            $table->string('effect_kind', 16);
            $table->char('transaction_key', 64);
            $table->char('parent_transaction_key', 64);
            $table->char('evidence_fingerprint', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('currency_qualified');
            $table->text('facts');
            $table->timestamp('recorded_at', 6);
            $table->unique(['payment_dispatch_preparation_id', 'evidence_fingerprint'], 'effect_evidence_unique');
            $table->index(['payment_association_scope_id', 'transaction_key'], 'effect_entity_index');
            $table->index(['payment_association_scope_id', 'parent_transaction_key'], 'effect_parent_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_operation_effect_associations');
    }
};
