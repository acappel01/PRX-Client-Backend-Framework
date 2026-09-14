<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_touchpoints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('touchpoint_id')->unique();
            $table->foreignId('canonical_event_id')->unique()->constrained()->restrictOnDelete();
            $table->string('evidence', 48);
            $table->longText('source_tuple');
            $table->dateTime('occurred_at', 6);
            $table->dateTime('recorded_at', 6);
        });
        Schema::create('canonical_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->foreignId('canonical_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('integration_instance_id')->constrained()->restrictOnDelete();
            $table->string('operation', 32);
            $table->dateTime('recorded_at', 6);
            $table->unique(['canonical_event_id', 'integration_instance_id', 'operation'], 'canonical_delivery_identity_unique');
        });
        Schema::create('canonical_delivery_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('canonical_delivery_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('evaluation_schema_version');
            $table->string('status', 32);
            $table->json('reasons');
            $table->longText('projection');
            $table->longText('policy_evidence');
            $table->dateTime('evaluated_at', 6);
        });
    }

    public function down(): void
    {
        // Preserve populated history before any authorized schema rollback.
        Schema::dropIfExists('canonical_delivery_evaluations');
        Schema::dropIfExists('canonical_deliveries');
        Schema::dropIfExists('attribution_touchpoints');
    }
};
