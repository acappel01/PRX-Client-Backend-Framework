<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use App\Services\Patient\PatientSecurityLog;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sign-in, sign-out and the patient's own security history.
 *
 * Every event is asserted with the EXACT IP and user agent the request carried,
 * never just its type — a row that exists with a null IP is the failure this
 * log exists to prevent, and a presence check passes it.
 */
class SecurityEventsTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.9';

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->withHeader('User-Agent', self::UA);
    }

    private function login(string $email, string $password)
    {
        return $this->postJson('/api/v1/patient/auth/login', ['email' => $email, 'password' => $password]);
    }

    // ─── Sign-in ─────────────────────────────────────────────────────

    public function test_a_successful_sign_in_records_who_from_where_and_which_session(): void
    {
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);

        $this->login('sam@example.test', 'correct-horse')->assertOk();

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::LoginSucceeded, $event->type);
        $this->assertSame(SecurityEventActor::Patient, $event->actor_type);
        $this->assertSame($patient->id, $event->patient_id);
        $this->assertSame($patient->uuid, $event->patient_uuid);
        $this->assertSame(self::IP, $event->ip_address);
        $this->assertSame(self::UA, $event->user_agent);
        $this->assertSame($patient->tokens()->sole()->id, $event->token_id);
        $this->assertSame(PatientSecurityEvent::subjectHash('sam@example.test'), $event->subject_hash);
        $this->assertTrue($event->hasValidIntegrity());
    }

    public function test_a_wrong_password_is_recorded_against_the_account(): void
    {
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);

        $this->login('sam@example.test', 'wrong')->assertUnprocessable();

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::LoginFailed, $event->type);
        $this->assertSame(SecurityEventActor::Anonymous, $event->actor_type);
        $this->assertSame($patient->id, $event->patient_id);
        $this->assertSame(['reason' => 'bad_password'], $event->context);
        $this->assertSame(self::IP, $event->ip_address);
        $this->assertSame(self::UA, $event->user_agent);
        $this->assertNull($event->token_id);
        $this->assertSame(0, $patient->tokens()->count());
    }

    public function test_an_unknown_address_is_recorded_by_hash_alone(): void
    {
        $this->login('Nobody@Example.test', 'anything')->assertUnprocessable();

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::LoginFailed, $event->type);
        $this->assertNull($event->patient_id);
        $this->assertNull($event->patient_uuid);
        $this->assertSame(['reason' => 'unknown_account'], $event->context);
        $this->assertSame(PatientSecurityEvent::subjectHash('nobody@example.test'), $event->subject_hash);
        $this->assertSame(self::IP, $event->ip_address);

        // The address itself is nowhere in the row.
        $this->assertStringNotContainsStringIgnoringCase('nobody', json_encode($event->getAttributes()));
    }

    public function test_an_unknown_address_and_a_wrong_password_answer_identically(): void
    {
        Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);

        $wrong = $this->login('sam@example.test', 'wrong')->assertUnprocessable()->json();
        $unknown = $this->login('nobody@example.test', 'wrong')->assertUnprocessable()->json();

        $this->assertSame($wrong, $unknown);
    }

    /** Skipping bcrypt for an unknown address made the answer measurably faster. */
    public function test_an_unknown_address_still_spends_a_password_hash(): void
    {
        Hash::spy();

        $this->login('nobody@example.test', 'anything')->assertUnprocessable();

        Hash::shouldHaveReceived('make')->once()->with('anything');
    }

    public function test_a_broken_log_does_not_lock_patients_out_and_is_not_silent(): void
    {
        Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);
        Schema::drop('patient_security_events');
        Log::spy();

        $this->login('sam@example.test', 'correct-horse')->assertOk();

        Log::shouldHaveReceived('critical')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('login_succeeded', $context['type']);
            // Class only: an insert's message quotes its bindings.
            $this->assertStringNotContainsString(self::IP, json_encode($context));

            return true;
        });
    }

    // ─── Sign-out ────────────────────────────────────────────────────

    public function test_signing_out_ends_the_session_and_records_which_one(): void
    {
        $patient = Patient::factory()->create();
        $keep = $patient->createToken('phone', ['patient:*']);
        $end = $patient->createToken('laptop', ['patient:*']);

        $this->postJson('/api/v1/patient/auth/logout', [], ['Authorization' => "Bearer {$end->plainTextToken}"])->assertOk();

        $this->assertSame([$keep->accessToken->id], $patient->tokens()->pluck('id')->all());

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::Logout, $event->type);
        $this->assertSame($patient->id, $event->patient_id);
        $this->assertSame($end->accessToken->id, $event->token_id);
        $this->assertSame(self::IP, $event->ip_address);
        $this->assertSame(self::UA, $event->user_agent);
    }

    // ─── The patient's own history ───────────────────────────────────

    public function test_the_history_lists_only_this_patients_events_newest_first(): void
    {
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);
        Patient::factory()->create(['email' => 'other@example.test', 'password' => 'other-pass']);

        $this->travel(-2)->minutes();
        $this->login('sam@example.test', 'wrong');
        $this->login('other@example.test', 'other-pass');
        $this->travelBack();

        $token = $this->login('sam@example.test', 'correct-horse')->json('data.token');

        $response = $this->getJson('/api/v1/patient/security/events', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertJsonPath('meta.retention_days', 730);

        $this->assertSame([
            [
                'type' => 'login_succeeded',
                'label' => 'Signed in',
                'occurred_at' => PatientSecurityEvent::where('type', 'login_succeeded')->where('patient_id', $patient->id)->sole()->occurred_at->toIso8601String(),
                'ip_address' => self::IP,
                'user_agent' => self::UA,
                'actor' => 'you',
                'is_current_session' => true,
                'sessions_revoked' => null,
            ],
            [
                'type' => 'login_failed',
                'label' => 'Sign-in attempt failed',
                'occurred_at' => PatientSecurityEvent::where('type', 'login_failed')->sole()->occurred_at->toIso8601String(),
                'ip_address' => self::IP,
                'user_agent' => self::UA,
                'actor' => 'unverified',
                'is_current_session' => false,
                'sessions_revoked' => null,
            ],
        ], $response->json('data'));
    }

    public function test_only_the_sign_in_behind_this_session_is_marked_current(): void
    {
        Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);

        $this->travel(-1)->minutes();
        $this->login('sam@example.test', 'correct-horse')->assertOk();
        $this->travelBack();
        $laptop = $this->login('sam@example.test', 'correct-horse')->json('data.token');

        $this->assertSame(
            [true, false],
            collect($this->getJson('/api/v1/patient/security/events', ['Authorization' => "Bearer {$laptop}"])->assertOk()->json('data'))
                ->pluck('is_current_session')->all(),
        );
    }

    /** `reason` would tell a caller whether the address or the password was wrong. */
    public function test_the_history_exposes_nothing_internal(): void
    {
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);
        $this->login('sam@example.test', 'wrong');
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $body = $this->getJson('/api/v1/patient/security/events', ['Authorization' => "Bearer {$token}"])->assertOk()->getContent();

        foreach (['bad_password', 'reason', 'subject_hash', 'integrity', 'actor_user_id', 'patient_uuid', '"id"', $patient->uuid] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_staff_actions_show_the_patient_no_staff_address_or_browser(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;
        app(PatientSecurityLog::class)->record(
            SecurityEventType::SessionsRevoked,
            patient: $patient,
            client: new RequestContext('192.0.2.50', 'Support Desk Browser'),
            actor: SecurityEventActor::Operator,
            actorUserId: 1,
            context: ['reason' => 'operator', 'revoked' => 1],
        );

        $row = $this->getJson('/api/v1/patient/security/events', ['Authorization' => "Bearer {$token}"])->assertOk()->json('data.0');

        $this->assertSame('operator', $row['actor']);
        $this->assertNull($row['ip_address']);
        $this->assertNull($row['user_agent']);
        $this->assertSame(1, $row['sessions_revoked']);
    }

    public function test_the_retention_shown_is_the_setting(): void
    {
        $settings = app(PortalSettings::class);
        $settings->security_events_retention_days = 90;
        $settings->save();

        $patient = Patient::factory()->create();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $this->getJson('/api/v1/patient/security/events', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('meta.retention_days', 90)
            ->assertJsonPath('data', []);
    }

    public function test_the_limit_is_bounded(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $this->getJson('/api/v1/patient/security/events?limit=101', ['Authorization' => "Bearer {$token}"])
            ->assertUnprocessable();
    }

    public function test_the_history_requires_a_patient_session(): void
    {
        $this->getJson('/api/v1/patient/security/events')->assertUnauthorized();
    }
}
