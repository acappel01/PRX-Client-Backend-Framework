<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_association_scopes', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_account_key', 64);
            $table->string('environment', 16);
            $table->unique(['canonical_account_key', 'environment'], 'payment_association_scope_unique');
        });
        Schema::create('payment_transaction_associations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_association_scope_id')->constrained('payment_association_scopes', indexName: 'association_scope_fk')->restrictOnDelete();
            $table->foreignId('payment_dispatch_preparation_id')->constrained('payment_dispatch_preparations', indexName: 'association_preparation_fk')->restrictOnDelete();
            $table->foreignId('payment_operation_id')->constrained('payment_operations', indexName: 'association_operation_fk')->restrictOnDelete();
            $table->char('transaction_key', 64);
            $table->char('evidence_fingerprint', 64);
            $table->text('facts');
            $table->boolean('currency_qualified');
            $table->timestamp('recorded_at', 6);
            $table->unique(['payment_dispatch_preparation_id', 'evidence_fingerprint'], 'payment_association_evidence_unique');
            $table->index(['payment_association_scope_id', 'transaction_key'], 'payment_association_transaction_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transaction_associations');
        Schema::dropIfExists('payment_association_scopes');
    }
};
