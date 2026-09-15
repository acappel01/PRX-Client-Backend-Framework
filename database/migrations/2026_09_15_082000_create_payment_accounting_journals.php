<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounting_journals', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique('accounting_uuid_unique');
            $t->foreignId('order_id')->constrained('orders', indexName: 'accounting_order_fk')->restrictOnDelete();
            $t->uuid('order_uuid');
            $t->foreignId('payment_association_scope_id')->constrained('payment_association_scopes', indexName: 'accounting_scope_fk')->restrictOnDelete();
            $t->foreignId('payment_financial_observation_id')->constrained('payment_financial_observations', indexName: 'accounting_observation_fk')->restrictOnDelete();
            $t->unsignedBigInteger('parent_journal_id')->nullable();
            $t->foreign('parent_journal_id', 'accounting_parent_fk')->references('id')->on('payment_accounting_journals')->restrictOnDelete();
            $t->char('entity_key', 64);
            $t->string('kind', 16);
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->char('economic_fingerprint', 64);
            $t->text('facts');
            $t->timestamp('posted_at', 6);
            $t->unique(['payment_association_scope_id', 'entity_key'], 'accounting_entity_unique');
            $t->index(['order_id', 'id'], 'accounting_order_index');
        });
        Schema::create('payment_accounting_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_accounting_journal_id')->constrained('payment_accounting_journals', indexName: 'accounting_line_journal_fk')->restrictOnDelete();
            $t->string('account', 40);
            $t->unsignedBigInteger('debit_minor')->default(0);
            $t->unsignedBigInteger('credit_minor')->default(0);
            $t->unique(['payment_accounting_journal_id', 'account'], 'accounting_line_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounting_lines');
        Schema::dropIfExists('payment_accounting_journals');
    }
};
