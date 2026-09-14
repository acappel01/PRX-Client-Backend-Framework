<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_attempts', function (Blueprint $table): void {
            // Existing rows deliberately remain unbound; current settings are not evidence.
            $table->foreignId('provider_instance_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider_tenant_kind', 32)->nullable();
            $table->string('provider_client_id', 128)->nullable();
            $table->string('provider_sales_org_id', 128)->nullable();
            $table->char('order_fingerprint', 64)->nullable();
            $table->timestamp('receipt_received_at')->nullable();
        });
        Schema::create('checkout_reconciliation_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_instance_id')->constrained()->restrictOnDelete();
            $table->string('before_status', 32);
            $table->string('after_status', 32);
            $table->text('reason');
            $table->string('source', 32)->default('console');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_reconciliation_audits');
        Schema::table('checkout_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_instance_id');
            $table->dropColumn(['provider_tenant_kind', 'provider_client_id', 'provider_sales_org_id', 'order_fingerprint', 'receipt_received_at']);
        });
    }
};
