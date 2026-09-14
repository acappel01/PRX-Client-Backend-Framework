<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opaque provider identifiers must preserve case/accent distinctions.
        // SQLite/Postgres already compare these varchar values exactly.
        $opaqueCollation = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'utf8mb4_bin' : null;

        Schema::create('provider_instances', function (Blueprint $table) use ($opaqueCollation): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('provider', 64);
            $table->string('environment', 16);
            $table->string('external_account_id', 128)->collation($opaqueCollation);
            $table->timestamps();
            $table->unique(['provider', 'environment', 'external_account_id'], 'provider_instance_scope_unique');
        });
        Schema::create('customer_provider_links', function (Blueprint $table) use ($opaqueCollation): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_instance_id')->constrained()->restrictOnDelete();
            $table->string('chart_id', 64)->collation($opaqueCollation);
            $table->string('patient_id', 64)->collation($opaqueCollation)->nullable();
            $table->string('patient_number', 64)->collation($opaqueCollation)->nullable();
            $table->timestamps();
            $table->unique(['provider_instance_id', 'chart_id'], 'provider_chart_unique');
            $table->unique(['provider_instance_id', 'customer_id'], 'provider_customer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_provider_links');
        Schema::dropIfExists('provider_instances');
    }
};
