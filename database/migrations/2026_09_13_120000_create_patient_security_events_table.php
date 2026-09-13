<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The security history of a patient account: sign-ins (and failed attempts),
 * sign-outs, sessions revoked, links sent and used, passwords changed, and what
 * operators did to the account.
 *
 * APPEND-ONLY. No `updated_at`; the model refuses updates and deletes. The one
 * sanctioned removal is the retention prune, a query-level delete of whole rows
 * (PatientSecurityEvent::prunable()). A row is never redacted in place.
 *
 * TAMPER-EVIDENT, NOT TAMPER-PROOF. `integrity` is an HMAC over the row's
 * immutable columns keyed from APP_KEY, so anyone who edits a row without the
 * key — including someone with raw database access — leaves a row that fails
 * `patient-security-events:verify`. It does NOT reveal a deleted row; that needs
 * a hash chain, deliberately deferred (see docs/portal/dev.md).
 *
 * OUTLIVES THE ACCOUNT for the retention window. `patient_id` nulls when a
 * patient is force-deleted; `patient_uuid` is a copy that does not, so the row
 * still names its subject. `subject_hash` is a keyed hash of the email address,
 * written on every event addressed by email — including failed sign-ins for an
 * address with no account — so attempts against one address can be counted
 * without storing the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_security_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->char('patient_uuid', 36)->nullable();
            $table->char('subject_hash', 64)->nullable();

            $table->string('type', 64);
            $table->string('actor_type', 16);
            // No foreign key: a deleted operator must not rewrite the history of
            // what they did, and the id is part of the signed columns.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            // No foreign key: sessions are deleted, their history is not.
            $table->unsignedBigInteger('token_id')->nullable();

            // Server-derived — `$request->ip()` behind TRUSTED_PROXIES — never a
            // value the caller supplies in a body.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('context')->nullable();

            $table->char('integrity', 64);

            // DATETIME, not TIMESTAMP: see the patient_email_tokens migration for
            // the ON UPDATE CURRENT_TIMESTAMP trap. Whole seconds: the signature
            // covers this value as `Y-m-d H:i:s`, the format Eloquent writes.
            $table->dateTime('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['patient_id', 'occurred_at']);
            $table->index(['subject_hash', 'occurred_at']);
            $table->index(['ip_address', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_security_events');
    }
};
