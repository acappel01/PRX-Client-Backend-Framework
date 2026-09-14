<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $opaqueCollation = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'utf8mb4_bin' : null;

        Schema::create('canonical_events', function (Blueprint $table) use ($opaqueCollation): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('name', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->string('origin', 64)->collation($opaqueCollation);
            $table->string('environment', 32)->collation($opaqueCollation);
            $table->string('source', 64)->collation($opaqueCollation);
            $table->string('dedupe_key', 128)->collation($opaqueCollation);
            // History survives physical identity removal; these are references,
            // never evidence that an account owns an order or clinical chart.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('recorded_at', 6);
            $table->longText('payload');
            $table->unique(['origin', 'environment', 'source', 'dedupe_key'], 'canonical_event_source_unique');
            $table->index(['name', 'occurred_at']);
        });
    }

    public function down(): void
    {
        // Populated event history requires a preservation plan before rollback.
        Schema::dropIfExists('canonical_events');
    }
};
