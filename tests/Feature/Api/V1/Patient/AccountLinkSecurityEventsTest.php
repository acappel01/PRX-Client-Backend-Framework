<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use App\Models\PatientSecurityEvent;
use App\Services\PrescribeRx\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\V1\Patient\Concerns\ClaimFixtures;
use Tests\TestCase;

/**
 * Security events from the emailed-link flows: claim, create account, reset,
 * and the two "email me a link" requests.
 *
 * Also pins `requested_ip` / `consumed_ip` on the token rows, which nothing
 * asserted before — they were written, and a regression to null would have
 * passed every existing test.
 */
class AccountLinkSecurityEventsTest extends TestCase
{
    use ClaimFixtures, RefreshDatabase;

    private const IP = '198.51.100.23';

    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Firefox/130.0';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->mock(Client::class, fn ($mock) => $mock->shouldNotReceive('findPatientByEmail'));

        $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->withHeader('User-Agent', self::UA);
    }

    private function mint(string $purpose, array $attributes): string
    {
        $plain = PatientEmailToken::newPlainToken();

        PatientEmailToken::create(array_merge([
            'purpose' => $purpose,
            'token_hash' => PatientEmailToken::hash($plain),
            'expires_at' => now()->addHour(),
        ], $attributes));

        return $plain;
    }

    /** @return list<string> */
    private function types(): array
    {
        return PatientSecurityEvent::query()->orderBy('id')->pluck('type')->map->value->all();
    }

    private function assertFromThisRequest(PatientSecurityEvent $event): void
    {
        $this->assertSame(self::IP, $event->ip_address, "{$event->type->value} has the wrong IP");
        $this->assertSame(self::UA, $event->user_agent, "{$event->type->value} has the wrong user agent");
        $this->assertTrue($event->hasValidIntegrity());
    }

    // ─── Reset ───────────────────────────────────────────────────────

    public function test_a_reset_records_the_change_the_sessions_ended_and_the_first_verification(): void
    {
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'email_verified_at' => null]);
        $patient->createToken('phone');
        $patient->createToken('laptop');

        $token = $this->mint(PatientEmailToken::PURPOSE_PASSWORD_RESET, ['patient_id' => $patient->id, 'sent_to' => 'sam@example.test']);

        $this->postJson('/api/v1/patient/auth/password/reset', ['token' => $token, 'password' => 'a-new-password'])->assertOk();

        $this->assertSame(['password_changed', 'sessions_revoked', 'email_verified'], $this->types());

        $events = PatientSecurityEvent::orderBy('id')->get();
        $events->each(fn ($event) => $this->assertFromThisRequest($event));
        $events->each(fn ($event) => $this->assertSame($patient->id, $event->patient_id));
        $this->assertSame(['method' => 'reset_link'], $events[0]->context);
        $this->assertSame(['reason' => 'password_reset', 'revoked' => 2], $events[1]->context);

        $this->assertSame(self::IP, PatientEmailToken::sole()->consumed_ip);
    }

    public function test_a_reset_with_no_sessions_and_a_verified_address_records_only_the_change(): void
    {
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'email_verified_at' => now()]);
        $token = $this->mint(PatientEmailToken::PURPOSE_PASSWORD_RESET, ['patient_id' => $patient->id, 'sent_to' => 'sam@example.test']);

        $this->postJson('/api/v1/patient/auth/password/reset', ['token' => $token, 'password' => 'a-new-password'])->assertOk();

        $this->assertSame(['password_changed'], $this->types());
    }

    public function test_a_refused_reset_records_nothing(): void
    {
        Patient::factory()->create(['email' => 'sam@example.test']);

        $this->postJson('/api/v1/patient/auth/password/reset', ['token' => str_repeat('a', 43), 'password' => 'a-new-password'])
            ->assertUnprocessable();

        $this->assertSame([], $this->types());
    }

    // ─── Create account ──────────────────────────────────────────────

    public function test_creating_an_account_records_it_with_the_session_it_opened(): void
    {
        $lead = $this->order();
        $token = $this->mint(PatientEmailToken::PURPOSE_CREATE_ACCOUNT, ['lead_id' => $lead->id, 'sent_to' => 'buyer@example.test']);

        $this->postJson('/api/v1/patient/auth/create-account', ['token' => $token, 'password' => 'a-new-password'])->assertCreated();

        $patient = Patient::sole();
        $this->assertSame(['account_created', 'record_claimed'], $this->types());

        $created = PatientSecurityEvent::where('type', 'account_created')->sole();
        $this->assertFromThisRequest($created);
        $this->assertSame($patient->id, $created->patient_id);
        $this->assertSame($patient->tokens()->sole()->id, $created->token_id);
        $this->assertSame(PatientSecurityEvent::subjectHash('buyer@example.test'), $created->subject_hash);

        $this->assertSame(self::IP, PatientEmailToken::sole()->consumed_ip);
    }

    // ─── Claim ───────────────────────────────────────────────────────

    public function test_a_first_claim_records_the_record_the_verification_and_the_other_sessions_ended(): void
    {
        $patient = Patient::factory()->create(['email' => 'buyer@example.test', 'email_verified_at' => null]);
        $patient->createToken('opened-before-verification');
        $current = $patient->createToken('portal');

        $token = $this->mint(PatientEmailToken::PURPOSE_CLAIM, ['lead_id' => $this->order()->id, 'sent_to' => 'buyer@example.test']);

        $this->withToken($current->plainTextToken)->postJson('/api/v1/patient/claim', ['token' => $token])->assertOk();

        $this->assertSame(['record_claimed', 'email_verified', 'sessions_revoked'], $this->types());

        $events = PatientSecurityEvent::orderBy('id')->get();
        $events->each(fn ($event) => $this->assertFromThisRequest($event));
        $events->each(fn ($event) => $this->assertSame($current->accessToken->id, $event->token_id));
        $this->assertSame(['reason' => 'first_verification', 'revoked' => 1], $events[2]->context);

        $this->assertSame(self::IP, PatientEmailToken::sole()->consumed_ip);
    }

    // ─── Links sent ──────────────────────────────────────────────────

    public function test_a_claim_link_that_went_out_is_recorded_and_one_that_did_not_is_not(): void
    {
        $this->enableClaimMail();
        $patient = $this->actingAsPatient();

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);
        $this->assertSame([], $this->types(), 'No order matched, and the history must not say otherwise.');

        $this->order();
        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::ClaimLinkSent, $event->type);
        $this->assertSame($patient->id, $event->patient_id);
        $this->assertFromThisRequest($event);
        $this->assertSame(self::IP, PatientEmailToken::sole()->requested_ip);
    }

    public function test_a_reset_link_request_is_recorded_as_unverified_with_the_requesters_ip_and_browser(): void
    {
        $this->enableClaimMail();
        $patient = Patient::factory()->create(['email' => 'sam@example.test']);

        $this->postJson('/api/v1/patient/auth/password/forgot', ['email' => 'sam@example.test'])->assertStatus(202);

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::ResetLinkSent, $event->type);
        $this->assertSame(SecurityEventActor::Anonymous, $event->actor_type);
        $this->assertSame($patient->id, $event->patient_id);
        // Through the queued job: the user agent crosses the queue with the IP.
        $this->assertFromThisRequest($event);
        $this->assertSame(self::IP, PatientEmailToken::sole()->requested_ip);
    }

    public function test_a_create_account_link_is_recorded_by_address_hash_alone(): void
    {
        $this->enableClaimMail();
        $this->order();

        $this->postJson('/api/v1/patient/auth/register', ['email' => 'Buyer@Example.test'])->assertStatus(202);

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::CreateAccountLinkSent, $event->type);
        $this->assertNull($event->patient_id);
        $this->assertSame(PatientSecurityEvent::subjectHash('buyer@example.test'), $event->subject_hash);
        $this->assertFromThisRequest($event);
    }

    public function test_an_address_with_nothing_records_nothing(): void
    {
        $this->enableClaimMail();

        $this->postJson('/api/v1/patient/auth/register', ['email' => 'nobody@example.test'])->assertStatus(202);

        $this->assertSame([], $this->types());
        $this->assertSame(0, Lead::count());
    }
}
