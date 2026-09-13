<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every signed webhook this install accepts, from any provider, recorded
 * BEFORE it is acted on — so a handler that throws, or an event for a record
 * that does not exist here yet, is kept and can be replayed instead of lost.
 *
 * `dedupe_key` is what makes at-least-once delivery safe: a provider retry of
 * the same delivery hits the unique index and is acknowledged without being
 * processed twice. It hashes the provider's own delivery id when one is signed
 * into the body, and a composite of the event otherwise.
 *
 * `payload` is an ALLOWLIST of the envelope (ids, statuses, timestamps,
 * amounts, tracking), never the raw body: some provider events carry free text
 * typed by clinic staff (a cancellation reason, a refill note), which this
 * install does not keep. `payload_hash` is over the raw body.
 *
 * No invalid-signature request is ever written here — that would hand an
 * unauthenticated caller an unbounded write path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source', 32);
            $table->string('provider_event_id', 64)->nullable();
            $table->string('event_type', 64);
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_ref', 64)->nullable();
            // DATETIME, not TIMESTAMP: see patient_email_tokens.
            $table->dateTime('occurred_at')->nullable();
            $table->char('payload_hash', 64);
            $table->char('dedupe_key', 64)->unique();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('received');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('matched_type', 64)->nullable();
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'event_type']);
            $table->index(['subject_type', 'subject_ref']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_webhook_events');
    }
};
