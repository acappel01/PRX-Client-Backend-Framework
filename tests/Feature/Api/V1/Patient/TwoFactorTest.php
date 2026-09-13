<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\CompleteTwoFactorLoginAction;
use App\Actions\Patient\RegenerateRecoveryCodesAction;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Events\Patient\TwoFactorEnrolled;
use App\Http\Middleware\EnsurePatientTwoFactorEnrolled;
use App\Jobs\Patient\SendTwoFactorNoticeJob;
use App\Models\Patient;
use App\Models\PatientAuthChallenge;
use App\Models\PatientEmailToken;
use App\Models\PatientRecoveryCode;
use App\Models\PatientSecurityEvent;
use App\Services\Patient\TwoFactor;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Patient two-step verification: TOTP + recovery codes, a hashed challenge
 * between password and session, and the off / optional / required policy.
 *
 * TOTP codes are derived from the REAL clock (google2fa reads time()), so
 * `travel()` moves challenge expiry but not codes; neighbouring codes are made
 * with oathTotp at an explicit step instead.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Bus::fake([SendTwoFactorNoticeJob::class]);
    }

    private function policy(string $policy): void
    {
        $settings = app(PortalSettings::class);
        $settings->two_factor_policy = $policy;
        $settings->save();
    }

    /** @return array{0: Patient, 1: string, 2: list<string>} patient, secret, recovery codes */
    private function enrolledPatient(array $attributes = []): array
    {
        $patient = Patient::factory()->create(array_merge(['email' => 'sam@example.test', 'password' => self::PASSWORD], $attributes));
        $secret = app(TwoFactor::class)->newSecret();
        $patient->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $codes = app(TwoFactor::class)->replaceRecoveryCodes($patient);

        return [$patient, $secret, $codes];
    }

    private function code(string $secret, int $offset = 0): string
    {
        $google2fa = app(Google2FA::class);

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $offset);
    }

    private function login(string $email = 'sam@example.test', string $password = self::PASSWORD)
    {
        return $this->postJson('/api/v1/patient/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function challenge(): string
    {
        return $this->login()->assertOk()->assertJsonPath('data.two_factor_required', true)->json('data.challenge');
    }

    private function exchange(string $challenge, array $body)
    {
        return $this->postJson('/api/v1/patient/auth/two-factor', ['challenge' => $challenge] + $body);
    }

    private function bearer(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => "Bearer {$token}"];
    }

    // ─── Sign-in ─────────────────────────────────────────────────────

    public function test_a_correct_password_on_a_two_step_account_opens_no_session(): void
    {
        [$patient] = $this->enrolledPatient();

        $response = $this->login()->assertOk();

        $this->assertTrue($response->json('data.two_factor_required'));
        $this->assertNull($response->json('data.token'));
        $this->assertNull($response->json('data.patient'));
        $this->assertTrue(PatientEmailToken::looksValid($response->json('data.challenge')));
        $this->assertSame(0, $patient->tokens()->count());

        $challenged = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::TwoFactorChallenged, $challenged->type);
        $this->assertSame(SecurityEventActor::Anonymous, $challenged->actor_type);
    }

    public function test_a_challenge_is_not_a_bearer_token(): void
    {
        $this->enrolledPatient();

        $this->getJson('/api/v1/patient/auth/me', $this->bearer($this->challenge()))->assertUnauthorized();
    }

    public function test_wrong_password_and_unknown_address_answer_the_same_with_or_without_two_step(): void
    {
        $this->enrolledPatient();
        Patient::factory()->create(['email' => 'plain@example.test', 'password' => self::PASSWORD]);

        $onTwoStep = $this->login('sam@example.test', 'wrong')->assertUnprocessable()->json();
        $onPlain = $this->login('plain@example.test', 'wrong')->assertUnprocessable()->json();
        $unknown = $this->login('nobody@example.test', 'wrong')->assertUnprocessable()->json();

        $this->assertSame($onPlain, $onTwoStep);
        $this->assertSame($onPlain, $unknown);
    }

    public function test_the_code_exchanges_the_challenge_for_a_session_that_records_the_second_factor(): void
    {
        [$patient, $secret] = $this->enrolledPatient();

        $token = $this->exchange($this->challenge(), ['code' => $this->code($secret)])
            ->assertOk()
            ->assertJsonPath('data.patient.two_factor.enabled', true)
            ->json('data.token');

        $this->getJson('/api/v1/patient/auth/me', $this->bearer($token))->assertOk();

        $session = $patient->tokens()->sole();
        $this->assertNotNull($session->two_factor_verified_at);
        $this->assertNotNull($session->expires_at);

        $succeeded = PatientSecurityEvent::where('type', SecurityEventType::LoginSucceeded)->sole();
        $this->assertSame(['method' => 'totp'], $succeeded->context);
        $this->assertSame($session->id, $succeeded->token_id);
        $this->assertNotNull(PatientAuthChallenge::sole()->consumed_at);
    }

    public function test_a_challenge_works_once(): void
    {
        [, $secret] = $this->enrolledPatient();
        $challenge = $this->challenge();

        $this->exchange($challenge, ['code' => $this->code($secret)])->assertOk();
        $this->exchange($challenge, ['code' => $this->code($secret, 1)])->assertUnprocessable();
    }

    public function test_five_wrong_codes_burn_the_challenge_even_for_the_right_code(): void
    {
        [$patient, $secret] = $this->enrolledPatient();
        $challenge = $this->challenge();
        $wrong = $this->code($secret, 5);

        for ($i = 0; $i < 5; $i++) {
            $this->exchange($challenge, ['code' => $wrong])
                ->assertUnprocessable()
                ->assertJsonPath('errors.code.0', CompleteTwoFactorLoginAction::REFUSAL);
        }

        $this->exchange($challenge, ['code' => $this->code($secret)])->assertUnprocessable();

        $this->assertSame(0, $patient->tokens()->count());
        $this->assertNotNull(PatientAuthChallenge::sole()->voided_at);
        $failures = PatientSecurityEvent::where('type', SecurityEventType::TwoFactorChallengeFailed)->orderBy('id')->get();
        $this->assertCount(5, $failures);
        $this->assertEquals(['locked' => true, 'remaining_attempts' => 0], $failures->last()->context);
    }

    public function test_a_code_cannot_be_used_twice_but_the_next_one_can(): void
    {
        [, $secret] = $this->enrolledPatient();
        $current = $this->code($secret);

        $this->exchange($this->challenge(), ['code' => $current])->assertOk();

        // A NEW challenge, so a refusal can only be the replay guard.
        $this->exchange($this->challenge(), ['code' => $current])->assertUnprocessable();
        $this->exchange($this->challenge(), ['code' => $this->code($secret, 1)])->assertOk();
    }

    public function test_only_codes_within_one_step_are_accepted(): void
    {
        [, $secret] = $this->enrolledPatient();

        $this->exchange($this->challenge(), ['code' => $this->code($secret, 2)])->assertUnprocessable();
        $this->exchange($this->challenge(), ['code' => $this->code($secret, -2)])->assertUnprocessable();
    }

    public function test_an_expired_challenge_refuses_the_right_code(): void
    {
        [, $secret] = $this->enrolledPatient();
        $challenge = $this->challenge();

        $this->travel(6)->minutes();

        $this->exchange($challenge, ['code' => $this->code($secret)])->assertUnprocessable();
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        [$patient, , $codes] = $this->enrolledPatient();

        $this->exchange($this->challenge(), ['recovery_code' => strtolower(str_replace('-', ' ', $codes[0]))])->assertOk();
        $this->exchange($this->challenge(), ['recovery_code' => $codes[0]])->assertUnprocessable();

        $used = PatientSecurityEvent::where('type', SecurityEventType::RecoveryCodeUsed)->sole();
        $this->assertSame(['remaining' => 7], $used->context);
        $this->assertSame(['method' => 'recovery_code'], PatientSecurityEvent::where('type', SecurityEventType::LoginSucceeded)->sole()->context);
        $this->assertSame(7, app(TwoFactor::class)->remainingRecoveryCodes($patient));
    }

    public function test_guessing_across_challenges_hits_the_per_account_limit(): void
    {
        [$patient, $secret] = $this->enrolledPatient();
        $wrong = $this->code($secret, 5);

        // Minted directly: eleven sign-ins would hit the per-IP `auth` limiter
        // first, and the test would pass on the wrong 429.
        $mint = function () use ($patient): string {
            $plain = PatientEmailToken::newPlainToken();
            PatientAuthChallenge::create([
                'patient_id' => $patient->id, 'token_hash' => PatientEmailToken::hash($plain),
                'expires_at' => now()->addMinutes(5),
            ]);

            return $plain;
        };

        // Five a minute keeps the per-IP `auth` limiter (10/min) out of it too.
        for ($i = 0; $i < 10; $i++) {
            if ($i === 5) {
                $this->travel(61)->seconds();
            }

            $this->exchange($mint(), ['code' => $wrong])->assertUnprocessable();
        }

        $this->travel(61)->seconds();

        $this->exchange($mint(), ['code' => $this->code($secret)])
            ->assertStatus(429)
            ->assertJsonPath('message', CompleteTwoFactorLoginAction::TOO_MANY);
    }

    // ─── Setup ───────────────────────────────────────────────────────

    public function test_setup_is_not_offered_under_the_off_policy(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $this->postJson('/api/v1/patient/two-factor/setup', [], $this->bearer($token))->assertForbidden();
        $this->getJson('/api/v1/patient/two-factor', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.offered', false);
    }

    public function test_setting_up_and_confirming_turns_it_on_and_returns_recovery_codes_once(): void
    {
        $this->policy('optional');
        Event::fake([TwoFactorEnrolled::class]);
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'password' => self::PASSWORD]);
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $this->postJson('/api/v1/patient/two-factor/setup', [], $this->bearer($token))->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/patient/two-factor/setup', ['password' => 'wrong'], $this->bearer($token))->assertJsonValidationErrors('password');
        $this->assertNull($patient->fresh()->two_factor_pending_secret);

        $setup = $this->postJson('/api/v1/patient/two-factor/setup', ['password' => self::PASSWORD], $this->bearer($token))->assertOk();
        $secret = $setup->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_uri'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setup->json('data.qr_code'));
        $this->assertFalse($patient->fresh()->hasTwoFactor(), 'Setup alone must not turn it on.');

        $codes = $this->postJson('/api/v1/patient/two-factor/confirm', ['code' => $this->code($secret)], $this->bearer($token))
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $codes[0]);
        $fresh = $patient->fresh();
        $this->assertTrue($fresh->hasTwoFactor());
        $this->assertNull($fresh->two_factor_pending_secret);

        $this->assertSame(['method' => 'new'], PatientSecurityEvent::where('type', SecurityEventType::TwoFactorEnrolled)->sole()->context);
        Event::assertDispatched(TwoFactorEnrolled::class);
        Bus::assertDispatched(SendTwoFactorNoticeJob::class, fn ($job) => $job->kind === SendTwoFactorNoticeJob::ENABLED);

        // The code that confirmed setup cannot be replayed as the first sign-in.
        $this->exchange($this->challenge(), ['code' => $this->code($secret)])->assertUnprocessable();
    }

    public function test_confirm_refuses_a_wrong_code_and_a_stale_setup(): void
    {
        $this->policy('optional');
        $patient = Patient::factory()->create();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;
        $secret = $this->postJson('/api/v1/patient/two-factor/setup', ['password' => 'password'], $this->bearer($token))->json('data.secret');

        $this->postJson('/api/v1/patient/two-factor/confirm', ['code' => $this->code($secret, 3)], $this->bearer($token))->assertUnprocessable();

        $this->travel(16)->minutes();
        $token = $patient->createToken('t2', ['patient:*'])->plainTextToken;
        $this->postJson('/api/v1/patient/two-factor/confirm', ['code' => $this->code($secret)], $this->bearer($token))->assertUnprocessable();

        $this->assertFalse($patient->fresh()->hasTwoFactor());
    }

    public function test_replacing_an_authenticator_needs_a_current_code(): void
    {
        [$patient, $secret, $codes] = $this->enrolledPatient();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $this->postJson('/api/v1/patient/two-factor/setup', [], $this->bearer($token))->assertUnprocessable();
        $this->assertNull($patient->fresh()->two_factor_pending_secret);

        // The lost-phone path: a recovery code.
        $this->postJson('/api/v1/patient/two-factor/setup', ['code' => $codes[0]], $this->bearer($token))->assertOk();
        $this->assertSame($secret, $patient->fresh()->two_factor_secret, 'The confirmed secret stays until the new one is confirmed.');
    }

    // ─── Recovery codes and turning it off ──────────────────────────

    public function test_new_recovery_codes_need_an_authenticator_code_and_retire_the_old_set(): void
    {
        [$patient, $secret, $old] = $this->enrolledPatient();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $this->postJson('/api/v1/patient/two-factor/recovery-codes', ['code' => $old[1]], $this->bearer($token))
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', RegenerateRecoveryCodesAction::REFUSAL);
        $this->assertSame(8, app(TwoFactor::class)->remainingRecoveryCodes($patient), 'A refused recovery code must not be spent.');

        $new = $this->postJson('/api/v1/patient/two-factor/recovery-codes', ['code' => $this->code($secret)], $this->bearer($token))
            ->assertOk()
            ->json('data.recovery_codes');

        $this->exchange($this->challenge(), ['recovery_code' => $old[2]])->assertUnprocessable();
        $this->exchange($this->challenge(), ['recovery_code' => $new[0]])->assertOk();
    }

    public function test_turning_it_off_needs_both_the_password_and_a_code(): void
    {
        $this->policy('optional');
        [$patient, $secret] = $this->enrolledPatient();
        $other = $patient->createToken('phone', ['patient:*']);
        $current = $patient->createToken('this', ['patient:*']);

        $this->postJson('/api/v1/patient/two-factor/disable', ['password' => 'wrong', 'code' => $this->code($secret)], $this->bearer($current->plainTextToken))->assertUnprocessable();
        $this->postJson('/api/v1/patient/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret, 4)], $this->bearer($current->plainTextToken))->assertUnprocessable();
        $this->assertTrue($patient->fresh()->hasTwoFactor());

        $this->postJson('/api/v1/patient/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)], $this->bearer($current->plainTextToken))->assertOk();

        $fresh = $patient->fresh();
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertNull($fresh->getRawOriginal('two_factor_secret'));
        $this->assertSame(0, PatientRecoveryCode::count());
        $this->assertSame([$current->accessToken->id], $patient->tokens()->pluck('id')->all());
        $this->assertNotNull($other);
        Bus::assertDispatched(SendTwoFactorNoticeJob::class, fn ($job) => $job->kind === SendTwoFactorNoticeJob::DISABLED);
    }

    // ─── Policy ──────────────────────────────────────────────────────

    public function test_required_confines_an_unenrolled_session_to_setup(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        // Minted BEFORE the switch: confinement is judged per request.
        $this->policy('required');

        $this->getJson('/api/v1/patient/security/events', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('code', EnsurePatientTwoFactorEnrolled::CODE);

        $this->getJson('/api/v1/patient/session', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/patient/auth/me', $this->bearer($token))->assertOk()->assertJsonPath('data.two_factor.setup_required', true);
        $this->getJson('/api/v1/patient/two-factor', $this->bearer($token))->assertOk()->assertJsonPath('data.setup_required', true);

        $secret = $this->postJson('/api/v1/patient/two-factor/setup', ['password' => 'password'], $this->bearer($token))->assertOk()->json('data.secret');
        $this->postJson('/api/v1/patient/two-factor/confirm', ['code' => $this->code($secret)], $this->bearer($token))->assertOk();

        $this->getJson('/api/v1/patient/security/events', $this->bearer($token))->assertOk();
    }

    public function test_required_refuses_turning_it_off(): void
    {
        [$patient, $secret] = $this->enrolledPatient();
        $this->policy('required');
        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;

        $this->postJson('/api/v1/patient/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)], $this->bearer($token))->assertForbidden();
        $this->assertTrue($patient->fresh()->hasTwoFactor());
    }

    /** Switching the policy off must never lower an account below what its owner chose. */
    public function test_off_still_challenges_an_account_that_has_it(): void
    {
        [$patient, $secret] = $this->enrolledPatient();

        $this->challenge();

        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;
        $this->postJson('/api/v1/patient/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)], $this->bearer($token))->assertOk();
    }

    // ─── Interactions ────────────────────────────────────────────────

    public function test_a_password_reset_keeps_two_step_on(): void
    {
        [$patient, $secret] = $this->enrolledPatient(['email_verified_at' => now()]);
        $waiting = $this->challenge();
        $patient->forceFill(['two_factor_pending_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_pending_at' => now()])->save();

        $plain = PatientEmailToken::newPlainToken();
        PatientEmailToken::create([
            'purpose' => PatientEmailToken::PURPOSE_PASSWORD_RESET, 'patient_id' => $patient->id,
            'sent_to' => 'sam@example.test', 'token_hash' => PatientEmailToken::hash($plain), 'expires_at' => now()->addHour(),
        ]);
        $this->postJson('/api/v1/patient/auth/password/reset', ['token' => $plain, 'password' => 'a-brand-new-password'])->assertOk();

        $fresh = $patient->fresh();
        $this->assertTrue($fresh->hasTwoFactor());
        $this->assertNull($fresh->two_factor_pending_secret);
        $this->exchange($waiting, ['code' => $this->code($secret)])->assertUnprocessable();

        $this->login('sam@example.test', 'a-brand-new-password')->assertOk()->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.token');
    }

    // ─── At rest ─────────────────────────────────────────────────────

    public function test_secrets_are_encrypted_hashed_and_never_serialised(): void
    {
        [$patient, $secret, $codes] = $this->enrolledPatient();

        $raw = DB::table('patients')->where('id', $patient->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, decrypt($raw, false));

        $this->assertSame(hash('sha256', str_replace('-', '', $codes[0])), PatientRecoveryCode::orderBy('id')->first()->code_hash);

        $serialised = json_encode($patient->fresh()->toArray());
        foreach (['two_factor_secret', 'two_factor_last_timestep', 'two_factor_pending_secret', $secret] as $leak) {
            $this->assertStringNotContainsString($leak, $serialised);
        }

        $token = $patient->createToken('t', ['patient:*'])->plainTextToken;
        $body = $this->getJson('/api/v1/patient/auth/me', $this->bearer($token))->getContent();
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('two_factor_secret', $body);
    }

    public function test_dead_challenges_are_pruned_after_a_day(): void
    {
        $this->enrolledPatient();
        $this->challenge();

        $this->travel(2)->days();
        $this->artisan('model:prune', ['--model' => [PatientAuthChallenge::class]]);

        $this->assertSame(0, PatientAuthChallenge::count());
        $this->assertSame(0, PersonalAccessToken::count());
    }
}
