<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transport_invocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_dispatch_attempt_id')->unique('transport_invocation_attempt_unique');
            $table->foreign('payment_dispatch_attempt_id', 'transport_invocation_attempt_fk')->references('id')->on('payment_dispatch_attempts')->restrictOnDelete();
            $table->timestamp('claimed_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transport_invocations');
    }
};
