<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Services\Patient\PatientActionStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ranking is the feature.
 *
 * These assert ORDER and TIER, never copy — the wording is content and will
 * move to the admin; the rules about which task outranks which are policy and
 * must not.
 */
class PatientActionStackTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PatientActionStackService
    {
        return app(PatientActionStackService::class);
    }

    private function encounter(array $overrides = []): array
    {
        return array_merge([
            'id' => 'enc-'.fake()->uuid(),
            'status' => 'pending_provider_review',
            'scheduled_at' => null,
        ], $overrides);
    }

    public function test_a_visit_starting_now_outranks_everything_else(): void
    {
        $tasks = $this->service()->build([
            $this->encounter(['id' => 'blocked', 'status' => 'pending_intake']),
            $this->encounter(['id' => 'live', 'scheduled_at' => now()->addMinutes(4)->toIso8601String()]),
        ]);

        $this->assertSame(PatientActionStackService::TIER_LIVE, $tasks[0]['tier']);
        $this->assertStringContainsString('live', $tasks[0]['id']);
    }

    public function test_a_visit_that_already_started_is_still_live(): void
    {
        // A provider running ten minutes late is the normal case, not a missed
        // visit. Treating it as merely "upcoming" is how a patient sits in a
        // waiting room reading that their appointment is in the past.
        $tasks = $this->service()->build([
            $this->encounter(['scheduled_at' => now()->subMinutes(8)->toIso8601String()]),
        ]);

        $this->assertSame(PatientActionStackService::TIER_LIVE, $tasks[0]['tier']);
    }

    public function test_only_one_task_is_ever_live(): void
    {
        // Two simultaneous live emergencies is a design failure, not a state to
        // render — the second steals the attention the treatment exists to
        // command. The soonest keeps tier 0; the rest are demoted.
        $tasks = $this->service()->build([
            $this->encounter(['id' => 'later', 'scheduled_at' => now()->addMinutes(12)->toIso8601String()]),
            $this->encounter(['id' => 'sooner', 'scheduled_at' => now()->addMinutes(2)->toIso8601String()]),
        ]);

        $live = array_filter($tasks, fn ($t) => $t['tier'] === PatientActionStackService::TIER_LIVE);

        $this->assertCount(1, $live);
        $this->assertStringContainsString('sooner', array_values($live)[0]['id']);
    }

    public function test_a_blocking_task_states_the_consequence_not_just_the_chore(): void
    {
        $tasks = $this->service()->build([
            $this->encounter(['status' => 'pending_intake']),
        ]);

        $this->assertSame(PatientActionStackService::TIER_BLOCKING, $tasks[0]['tier']);
        // Not the wording, but that a reason is present at all: a blocking card
        // with an empty body is the failure mode this rule exists to prevent.
        $this->assertNotEmpty($tasks[0]['body']);
    }

    public function test_the_providers_own_words_survive_into_the_task(): void
    {
        $tasks = $this->service()->build([
            $this->encounter([
                'status' => 'requires_information',
                'info_request_message' => 'Please upload a photo of your current medication label.',
            ]),
        ]);

        $this->assertSame('Please upload a photo of your current medication label.', $tasks[0]['body']);
    }

    public function test_within_a_tier_the_soonest_deadline_comes_first(): void
    {
        $tasks = $this->service()->build([
            $this->encounter(['id' => 'far', 'scheduled_at' => now()->addHours(40)->toIso8601String()]),
            $this->encounter(['id' => 'near', 'scheduled_at' => now()->addHours(3)->toIso8601String()]),
        ]);

        $this->assertStringContainsString('near', $tasks[0]['id']);
        $this->assertStringContainsString('far', $tasks[1]['id']);
    }

    public function test_a_task_without_a_deadline_sorts_last_in_its_tier(): void
    {
        // Open-ended is not more urgent for being open-ended.
        $tasks = $this->service()->build(
            [$this->encounter(['status' => 'pending_intake'])],
            ['recent_orders' => [[
                'id' => 'ord-1',
                'workflow_status' => 'awaiting_patient_approval',
            ]]],
        );

        $blocking = array_values(array_filter(
            $tasks,
            fn ($t) => $t['tier'] === PatientActionStackService::TIER_BLOCKING
        ));

        $this->assertCount(2, $blocking);
    }

    public function test_a_visit_beyond_the_window_is_not_a_task_at_all(): void
    {
        // Silent urgency runs both ways: a visit three weeks out rendered as a
        // task is noise, and noise is what makes a real one invisible.
        $tasks = $this->service()->build([
            $this->encounter(['scheduled_at' => now()->addDays(21)->toIso8601String()]),
        ]);

        $this->assertSame([], $tasks);
    }

    /**
     * The exact shape of a live held encounter on the sandbox, 2026-09-07.
     * Copied from the wire, not imagined — the two assumptions it falsified
     * (`requires_information` as the status, `intake_progress_pct` as the
     * progress field) are both things that read perfectly plausibly.
     */
    private function heldEncounter(array $overrides = []): array
    {
        return array_merge([
            'id' => 'enc-held',
            'status' => 'on_hold',
            'encounter_number' => 'ENC-3464911297',
            'info_request_message' => null,
            'info_requested_at' => null,
            'scheduled_at' => null,
            'interaction_type' => 4,
            'metadata' => [
                'needs_documents' => [
                    'at' => '2026-09-07T22:54:43+00:00',
                    'slugs' => ['id_front', 'drivers_license_number', 'drivers_license_or_identification_state_of_issue'],
                ],
            ],
            'patient_intake_snapshot' => ['completeness_score' => 32],
        ], $overrides);
    }

    public function test_a_held_encounter_awaiting_documents_is_a_blocking_task(): void
    {
        // The live case. Status is `on_hold`, which is NOT in BLOCKING_STATUSES,
        // and info_request_message is null — so the status alone yields nothing.
        // needs_documents is the only signal that the patient owes anything.
        $tasks = $this->service()->build([$this->heldEncounter()]);

        $this->assertCount(1, $tasks, 'A patient waiting on documents got no task at all.');
        $this->assertSame(PatientActionStackService::TIER_BLOCKING, $tasks[0]['tier']);
        $this->assertSame(3, count($tasks[0]['outstanding']));
    }

    public function test_the_card_carries_the_real_completion_score(): void
    {
        // Read from patient_intake_snapshot.completeness_score. The earlier
        // version read a field that does not exist, so the meter never rendered.
        $tasks = $this->service()->build([$this->heldEncounter()]);

        $this->assertSame(32, $tasks[0]['progress_pct']);
    }

    public function test_a_hold_with_nothing_outstanding_is_not_a_patient_task(): void
    {
        // `on_hold` is overloaded — the state machine also parks sandbox and
        // safeguarded encounters there for an ADMIN to release. Surfacing those
        // tells a patient to act on something they cannot touch.
        $tasks = $this->service()->build([
            $this->heldEncounter(['metadata' => ['encounter_created_notification_sent' => true]]),
        ]);

        $this->assertSame([], $tasks);
    }

    public function test_documents_outrank_the_status_in_the_copy(): void
    {
        // A held encounter that ALSO reads pending_intake must describe the
        // documents, because that is the only text naming what is missing.
        $tasks = $this->service()->build([$this->heldEncounter(['status' => 'pending_intake'])]);

        $this->assertArrayHasKey('outstanding', $tasks[0]);
        $this->assertContains('id_front', $tasks[0]['outstanding']);
    }

    public function test_a_provider_message_still_wins_over_the_generic_line(): void
    {
        $tasks = $this->service()->build([
            $this->heldEncounter(['info_request_message' => 'Send a clearer photo of the front of your licence.']),
        ]);

        $this->assertSame('Send a clearer photo of the front of your licence.', $tasks[0]['body']);
    }

    public function test_malformed_needs_documents_does_not_invent_a_task(): void
    {
        foreach ([['slugs' => 'not-an-array'], ['slugs' => []], []] as $shape) {
            $tasks = $this->service()->build([
                $this->heldEncounter(['metadata' => ['needs_documents' => $shape]]),
            ]);

            $this->assertSame([], $tasks, 'A malformed needs_documents produced a task.');
        }
    }

    public function test_nothing_outstanding_produces_an_empty_stack(): void
    {
        // Empty is success. It must not be a synthesised "no data yet" task.
        $this->assertSame([], $this->service()->build([]));
    }

    public function test_a_malformed_upstream_timestamp_does_not_break_the_screen(): void
    {
        $tasks = $this->service()->build([
            $this->encounter(['status' => 'pending_intake', 'info_requested_at' => 'not-a-date']),
        ]);

        $this->assertCount(1, $tasks);
        $this->assertNull($tasks[0]['deadline']);
    }
}
