<?php

namespace App\Integrations;

use App\Integrations\Messages\ConsentState;
use App\Models\Lead;
use App\Models\LeadConsent;

/**
 * What this person has consented to, right now.
 *
 * ─── Reads the audit, and only the audit ───────────────────────────────
 *
 * `lead_consents` is the append-only record and the only thing that can say what
 * somebody actually agreed to. `leads.email_consent` / `sms_consent` are a cache
 * of it, written by `RecordConsentAction` at the same moment as the row, and
 * they are not consulted here at all.
 *
 * THAT PLACES THE WHOLE WEIGHT ON `RecordConsentAction` BEING THE ONLY WRITER,
 * so it is worth saying what happens if it is not. The lead form's consent
 * toggles used to move the booleans with no audit row behind them: an operator
 * entering somebody's opt-out changed a column nothing downstream reads, the
 * audit still said granted, and the next run subscribed them. Those toggles are
 * now display-only and the audit has an action that appends — the source of the
 * divergence, rather than a rule here that papers over it.
 *
 * A veto — treating a cached `false` as a refusal regardless of the audit — was
 * tried and removed. It reads as extra safety and is not: a row written straight
 * to the audit by an import leaves the cache at its default, so the veto would
 * silently discard real consent, and a rule that only sometimes matches the
 * record is the failure mode this project has already met with `Sensitive`.
 *
 * ─── Resolved at SEND time, from the database, on purpose ──────────────
 *
 * A workflow chain carries an attribute snapshot taken when the trigger fired
 * (see `RunWorkflowChain`), and the job may run after a withdrawal. Consent is
 * exactly the field where the stale answer is the harmful one, so this queries
 * rather than reading the snapshot — the same rule `FieldMap` already applies to
 * the PHI attestation, for the same reason: a revocation has to take effect on
 * the next run without anybody editing a workflow.
 *
 * ─── Fails closed ──────────────────────────────────────────────────────
 *
 * A subject that is not a lead, a lead with no rows, a withdrawal — all resolve
 * to `none()`. Nothing here infers consent from the absence of a refusal.
 */
class ConsentResolver
{
    /** Current reads require a caller-owned transaction; ordinary callers retain existing behavior. */
    public function resolve(?object $subject, bool $currentRead = false): ConsentState
    {
        if (! $subject instanceof Lead || $subject->getKey() === null) {
            return ConsentState::none();
        }

        // The latest decision per channel. Withdrawals are rows too, so the most
        // recent row for a channel is the answer whether it granted or revoked —
        // ordering by id as well as the timestamp keeps a grant and a withdrawal
        // captured in the same second from resolving arbitrarily.
        $latest = LeadConsent::query()
            ->where('lead_id', $subject->getKey())
            ->when($currentRead, fn ($query) => $query->lockForUpdate())
            ->orderByDesc('consented_at')
            ->orderByDesc('id')
            ->get(['channel', 'granted'])
            ->unique('channel');

        return ConsentState::forChannels(
            $latest->filter(fn (LeadConsent $row): bool => $row->granted)
                ->pluck('channel')
                ->all(),
        );
    }
}
