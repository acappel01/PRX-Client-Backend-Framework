<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\ClaimPatientRecordAction;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\RecordClaimed;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\V1\Patient\Concerns\ClaimFixtures;
use Tests\TestCase;

/**
 * `POST /patient/claim` — using the emailed link.
 *
 * Two sets of guards meet here. The token's own (unknown, spent, expired, sent
 * to someone else) all give ONE refusal. Behind them sit the chart-linking
 * guards from `LinkPatientToPrxChartAction` — the encounter as the only
 * evidence, no re-linking, one chart per account — which this file carries
 * forward from the endpoint it replaced, because that action is now reachable
 * only from here.
 *
 * The ordering tests matter as much as the refusals: a guard that fires AFTER
 * the token is consumed burns a link for a failure that was not the patient's,
 * and a verification stamped BEFORE the link succeeds claims a proof that did
 * not complete. Each is asserted on the rows, not the status.
 */
class ClaimRecordTest extends TestCase
{
    use ClaimFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** A live token row for `$lead`, minted directly so link-level guards are reachable. */
    private function tokenFor(Lead $lead, array $attributes = []): string
    {
        $plain = PatientEmailToken::newPlainToken();

        PatientEmailToken::create(array_merge([
            'purpose' => PatientEmailToken::PURPOSE_CLAIM,
            'lead_id' => $lead->id,
            'sent_to' => strtolower($lead->email),
            'token_hash' => PatientEmailToken::hash($plain),
            'expires_at' => now()->addHour(),
        ], $attributes));

        return $plain;
    }

    private function claim(string $token)
    {
        return $this->postJson('/api/v1/patient/claim', ['token' => $token]);
    }

    // ─── Happy path, end to end ──────────────────────────────────────

    public function test_the_emailed_link_connects_the_record_and_verifies_the_address(): void
    {
        $this->enableClaimMail();
        $patient = $this->actingAsPatient();
        $lead = $this->order();

        $token = $this->requestLinkAndReadToken();

        $this->claim($token)
            ->assertOk()
            ->assertJsonPath('data.patient.has_prx_chart', true)
            ->assertJsonPath('data.patient.email_verified', true);

        $patient->refresh();
        $this->assertSame(self::CHART, $patient->prx_patient_chart_id);
        $this->assertNotNull($patient->prx_chart_verified_at);
        $this->assertNotNull($patient->email_verified_at);
        $this->assertSame($patient->id, $lead->refresh()->patient_id);

        $row = PatientEmailToken::sole();
        $this->assertNotNull($row->consumed_at);
        $this->assertSame($patient->id, (int) $row->consumed_by_patient_id);
    }

    public function test_the_workflow_events_fire_without_the_token(): void
    {
        Event::fake([RecordClaimed::class, EmailVerified::class]);
        $patient = $this->actingAsPatient();

        $this->claim($this->tokenFor($this->order()))->assertOk();

        Event::assertDispatched(RecordClaimed::class, fn ($e) => $e->patient->is($patient)
            && array_keys(get_object_vars($e)) === ['patient']);
        Event::assertDispatched(EmailVerified::class, fn ($e) => $e->patient->is($patient)
            && array_keys(get_object_vars($e)) === ['patient']);
    }

    public function test_email_verified_fires_only_on_the_first_verification(): void
    {
        Event::fake([RecordClaimed::class, EmailVerified::class]);
        $this->actingAsPatient(['email_verified_at' => now()->subDay()]);

        $this->claim($this->tokenFor($this->order()))->assertOk();

        Event::assertDispatched(RecordClaimed::class);
        Event::assertNotDispatched(EmailVerified::class);
    }

    // ─── The token's own guards: one refusal for all of them ─────────

    public function test_a_link_works_once(): void
    {
        $this->actingAsPatient();
        $token = $this->tokenFor($this->order());

        $this->claim($token)->assertOk();

        $this->claim($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL);
    }

    public function test_a_link_spent_between_the_read_and_the_write_is_refused(): void
    {
        // The race the conditional UPDATE exists for, staged deterministically:
        // the non-locking read sees a live token, then another request spends it
        // before this one writes. Without the affected-rows check both would
        // link. The pre-check cannot see this — it has already passed.
        $patient = $this->actingAsPatient();
        $token = $this->tokenFor($this->order());

        PatientEmailToken::retrieved(function (PatientEmailToken $row): void {
            \Illuminate\Support\Facades\DB::table('patient_email_tokens')
                ->where('id', $row->id)
                ->update(['consumed_at' => now()]);
        });

        $this->claim($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL);

        $this->assertNull($patient->refresh()->prx_patient_chart_id);
        $this->assertNull($patient->email_verified_at);
    }

    public function test_an_expired_link_is_refused_and_links_nothing(): void
    {
        $patient = $this->actingAsPatient();
        $token = $this->tokenFor($this->order(), ['expires_at' => now()->subMinute()]);

        $this->claim($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL);

        $this->assertNull($patient->refresh()->prx_patient_chart_id);
        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }

    public function test_an_unknown_token_is_indistinguishable_from_a_spent_or_expired_one(): void
    {
        $this->actingAsPatient();

        $this->claim(PatientEmailToken::newPlainToken())
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL);

        $this->claim('not-a-token')
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL);
    }

    public function test_a_link_sent_to_another_address_cannot_be_used_from_this_account(): void
    {
        // 🔴 THE CONFUSED DEPUTY. An account that is not the mailbox's owner
        // holds a valid link (forwarded, intercepted, pasted into a chat). The
        // session's address must equal the address the link went to — and the
        // refusal must come BEFORE consumption, so the owner can still use it.
        $this->actingAsPatient(['email' => 'attacker@example.test']);
        $token = $this->tokenFor($this->order(['email' => 'victim@example.test']));

        $this->claim($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL);

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
        $this->assertDatabaseHas('patients', ['email' => 'attacker@example.test', 'prx_patient_chart_id' => null]);

        // The owner, signed in as themselves, still can.
        $owner = Patient::factory()->create(['email' => 'victim@example.test', 'prx_patient_chart_id' => null]);
        Sanctum::actingAs($owner, ['*']);

        $this->claim($token)->assertOk();
        $this->assertSame(self::CHART, $owner->refresh()->prx_patient_chart_id);
    }

    public function test_the_address_comparison_ignores_case(): void
    {
        $patient = $this->actingAsPatient(['email' => 'Buyer@Example.TEST']);
        $token = $this->tokenFor($this->order(['email' => 'buyer@example.test']));

        $this->claim($token)->assertOk();

        $this->assertSame(self::CHART, $patient->refresh()->prx_patient_chart_id);
    }

    // ─── Ordering: never burn a link, never claim a proof early ──────

    public function test_a_refused_link_is_not_consumed_and_the_address_is_not_verified(): void
    {
        // The record is already held by another account. The link action
        // refuses inside the claim transaction; consumption must roll back with
        // it and `email_verified_at` must stay null.
        Patient::factory()->create(['email' => 'first@example.test', 'prx_patient_chart_id' => self::CHART]);
        $patient = $this->actingAsPatient();
        $token = $this->tokenFor($this->order());

        $this->claim($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'That medical record is already linked to another account.');

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
        $this->assertNull($patient->refresh()->email_verified_at);
    }

    public function test_an_order_with_no_chart_yet_keeps_its_link_for_later(): void
    {
        // The provider has not returned a chart. Not the patient's fault, so
        // the link survives to be used once it has.
        $patient = $this->actingAsPatient();
        $lead = $this->order(chartOnEncounter: null);
        $token = $this->tokenFor($lead);

        $this->claim($token)->assertStatus(422);
        $this->assertNull(PatientEmailToken::sole()->consumed_at);

        \App\Models\Commerce\Encounter::factory()->create(['lead_id' => $lead->id, 'prescribe_rx_patient_id' => self::CHART]);

        $this->claim($token)->assertOk();
        $this->assertSame(self::CHART, $patient->refresh()->prx_patient_chart_id);
    }

    public function test_first_verification_revokes_other_sessions_but_keeps_this_one(): void
    {
        $patient = Patient::factory()->create(['email' => 'buyer@example.test', 'email_verified_at' => null]);
        $stale = $patient->createToken('opened-before-verification')->plainTextToken;
        $current = $patient->createToken('portal');

        $token = $this->tokenFor($this->order());

        $this->withToken($current->plainTextToken)
            ->postJson('/api/v1/patient/claim', ['token' => $token])
            ->assertOk();

        $this->assertSame([$current->accessToken->id], $patient->tokens()->pluck('id')->all());
        $this->assertNotNull($stale);
    }

    // ─── Chart-linking guards, carried forward from link-chart ───────

    public function test_the_chart_id_planted_on_the_lead_is_ignored_in_favour_of_the_encounter(): void
    {
        // `LeadIntakeController::complete` and `EmbedCompleteController` both
        // write `leads.prescribe_rx_patient_id` with no credential.
        $patient = $this->actingAsPatient();
        $token = $this->tokenFor($this->order(['prescribe_rx_patient_id' => 'planted-by-an-attacker']));

        $this->claim($token)->assertOk();

        $this->assertSame(self::CHART, $patient->refresh()->prx_patient_chart_id);
    }

    public function test_an_account_that_already_has_a_record_is_not_relinked(): void
    {
        $this->actingAsPatient(['prx_patient_chart_id' => 'existing-chart']);
        $token = $this->tokenFor($this->order());

        $this->claim($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'This account is already linked to a medical record.');

        $this->assertDatabaseHas('patients', ['email' => 'buyer@example.test', 'prx_patient_chart_id' => 'existing-chart']);
    }

    public function test_an_order_claimed_by_someone_else_is_refused(): void
    {
        $other = Patient::factory()->create(['email' => 'other@example.test']);
        $this->actingAsPatient();
        $token = $this->tokenFor($this->order(['patient_id' => $other->id]));

        $this->claim($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'That order has already been claimed.');
    }

    // ─── Surface ─────────────────────────────────────────────────────

    public function test_it_requires_a_patient_session(): void
    {
        $this->claim(PatientEmailToken::newPlainToken())->assertUnauthorized();
    }

    public function test_there_is_no_get_route_a_mail_scanner_could_spend_a_token_on(): void
    {
        $this->actingAsPatient();
        $token = $this->tokenFor($this->order());

        $this->getJson("/api/v1/patient/claim/{$token}")->assertNotFound();
        $this->getJson('/api/v1/patient/claim?token='.$token)->assertStatus(405);

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }
}
