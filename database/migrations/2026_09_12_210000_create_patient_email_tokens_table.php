<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use links emailed to a patient.
 *
 * A row is proof that a message carrying a secret was sent to one mailbox, and
 * — once consumed — that the person holding that mailbox acted on it. That is
 * the one thing a registration form cannot prove, and chart linking needs it.
 *
 * ONLY THE HASH IS STORED. The plain token exists in the email and nowhere
 * else, so a read of this table (a backup, a support query, an admin with
 * database access) yields nothing that can be clicked. sha256 rather than
 * bcrypt because the token carries 256 bits of entropy: a slow hash defends a
 * guessable secret, and this one is not guessable. It also lets the lookup be
 * an indexed equality, which a salted hash cannot be.
 *
 * BOUND TO THE ORDER AND THE ADDRESS, NOT TO WHOEVER ASKED. `patient_id` is
 * the requester, kept for the audit trail only; the claim check reads
 * `lead_id` and `sent_to`. See ClaimPatientRecordAction for why.
 *
 * `purpose` exists so a later plain "verify my email" or password reset reuses
 * this table rather than growing a second one with the same lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_email_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 32)->default('claim');

            // Requester. Audit only — never part of the claim check.
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();

            // The order this link would connect. A deleted lead takes its
            // outstanding links with it: there is nothing left to claim.
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();

            // The lowercased address the message went to, snapshotted at send
            // time. The claim requires the session's address to equal this.
            $table->string('sent_to');

            $table->char('token_hash', 64)->unique();
            // DATETIME, not TIMESTAMP: as the table's first NOT NULL timestamp,
            // MySQL with explicit_defaults_for_timestamp=0 would give it
            // ON UPDATE CURRENT_TIMESTAMP, and consuming a link would push its
            // expiry to "now".
            $table->dateTime('expires_at');

            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by_patient_id')->nullable()->constrained('patients')->nullOnDelete();

            // Server-derived, never from a header the caller controls beyond
            // what the proxy chain already vouches for.
            $table->string('requested_ip', 45)->nullable();
            $table->string('consumed_ip', 45)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_email_tokens');
    }
};
