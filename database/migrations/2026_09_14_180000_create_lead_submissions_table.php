<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_key')->unique();
            $table->char('owner_hash', 64);
            $table->char('request_fingerprint', 64);
            // A deleted Lead leaves a tombstone so its key cannot mint another Lead.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_submissions');
    }
};
