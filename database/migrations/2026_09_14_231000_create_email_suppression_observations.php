<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppression_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->nullable()->unique()->constrained('email_suppression_observations')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_instance_id')->constrained()->restrictOnDelete();
            $table->string('status', 24);
            $table->string('reason', 64);
            $table->text('evidence');
            $table->timestamp('started_at', 6);
            $table->timestamp('recorded_at', 6);
            $table->index(['lead_id', 'integration_instance_id', 'id'], 'suppression_subject_destination');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppression_observations');
    }
};
