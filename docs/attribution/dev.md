# Canonical event foundation

Implemented on September 14, 2026 in the isolated Customer commerce branch. The broader [unified attribution design](design.md) remains the delivery and reporting plan. This increment stores internal capture history; it does not enable pixels, send Klaviyo events, create Impact actions or establish payable conversions.

## Recording contract

`RecordCanonicalEventAction` writes immutable `CanonicalEvent` records with a UUID event identity, versioned name, origin/environment, source, case-sensitive deduplication key, occurrence/recording times, optional Lead/Customer references and an encrypted allowlisted payload. The row is the durable internal event/outbox entry; destination delivery state and workers are not implemented. Ordinary model updates and deletes are rejected. Payload, subject references and deduplication keys are hidden from generic serialization.

An identical source identity and business payload returns the original event. Changed content under that identity fails validation. Unique database constraints and savepoint recovery handle competing writers, including MySQL repeatable-read snapshots. This action is for trusted internal producers: supplying a model reference does not prove fresh authorization, ownership or consent. Producers must establish those separately in their state-transition transaction.

Only `lead.captured` and `quiz.completed`, schema version 1, are registered. `CreateLeadAction` records capture inside the same transaction as the Lead and consent rows; a validated quiz also records completion. Failure rolls back the local capture. Existing workflow signals retain their current behavior. No new destination is called by this event recorder.

Deduplication is currently per persisted Lead. Repeating a public lead POST still creates another Lead and another pair of events where applicable. Submission/session idempotency remains a separate planned change; the event ledger alone does not solve browser retry duplication.

## Source and goal projection

`LeadEventPayload` projects only the five UTM fields, approved goal keys and a quiz ID/definition fingerprint. It never exports generic Lead JSON or a raw quiz-answer blob. Generalized goals from active HealthGoals questions with General/Sensitive classification are retained for the intended nonclinical segmentation use. Conditions, medications, allergies, PHI-classified questions, unknown goals and inactive question/step/catalog entries are excluded.

UTM values are bounded; obvious URLs, query separators, control characters and the credential-bearing Lead UUID are dropped from this internal projection. This is a bounded input filter, not a general guarantee that arbitrary user text is fit for every destination. A future adapter must apply its destination field and purpose policy rather than forwarding the payload wholesale.

The capture event freezes the original source tuple before existing best-effort referral attribution updates the Lead's compatibility columns. It therefore preserves original capture evidence without redefining affiliate credit. Quiz fingerprints include the current definition and approved goal catalog. They detect definition changes; they are not an archive from which historical quiz definitions can be reconstructed. The already projected event payload remains immutable if the quiz changes later.

## Checkout and ownership relationship

Verified mailbox/chart claims now bind Lead, Customer and trusted local encounter orders. API checkout persists a local order intent and `CheckoutAttempt` before the provider request; its opaque context is included in metadata and its minimal provider receipt is encrypted separately before finalization. See [Customer implementation](../customers/dev.md) and [checkout implementation](../checkout/dev.md).

New API checkout contexts now pin a typed `ProviderInstance`, environment, both explicit routing IDs and request/order identity. [Local reconciliation](../checkout/reconciliation.md) can finish a retained verified receipt without resubmitting intake or replaying marketing automation. Legacy unbound attempts and attempts without receipts remain unresolved. Verified embed/session binding, provider reads/callback ingestion and checkout/payment canonical event producers remain future work. Do not use anonymous metadata or browser completion as a new ownership proof.

## Next increments

Add compatible lead-submission idempotency, append-only attribution touchpoints and verified context reconciliation. Introduce destination policy previews and a delivery ledger before routing canonical events through the existing Klaviyo workflow driver. Preserve consent withdrawal and suppression on every retry. Add the financial ledger before reporting captured revenue, lifetime value, reversals or affiliate entitlements. Build Impact and further pixel/server adapters through that same delivery contract.

Focused capture tests cover encrypted payloads, approved goals, clinical-field exclusion, transaction rollback and original source retention. Event tests cover replay/conflict handling, versioned schemas, immutability and serialization. Release validation and exact suite results are recorded with the Customer implementation and session handoff. All tests use disposable local data and fake provider responses.
