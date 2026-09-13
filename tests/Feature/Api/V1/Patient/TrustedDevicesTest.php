<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\ResetPatientTwoFactorAction;
use App\Actions\Patient\RevokePatientSessionsAction;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Jobs\Patient\SendTwoFactorNoticeJob;
use App\Models\Commerce\Encounter;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use App\Models\PatientSecurityEvent;
use App\Models\PatientTrustedDevice;
use App\Models\User;
use App\Services\Patient\TrustedDevices;
use App\Services\Patient\TwoFactor;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * "Trust this browser": after a two-step sign-in a browser skips the CODE —
 * never the password — until it expires or anything about the account's
 * security changes.
 */
class TrustedDevicesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse';

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';

    private int $stepOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Bus::fake([SendTwoFactorNoticeJob::class]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeader('User-Agent', self::UA);
    }

    /** @return array{0: Patient, 1: string} */
    private function enrolled(string $email = 'sam@example.test'): array
    {
        $patient = Patient::factory()->create(['email' => $email, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
        $secret = app(TwoFactor::class)->newSecret();
        $patient->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        app(TwoFactor::class)->replaceRecoveryCodes($patient);

        return [$patient, $secret];
    }

    /**
     * A fresh code each call: the replay guard refuses a step already used.
     * Steps −1, 0, +1 — so at most THREE codes per secret per test before the
     * window (±1 step) runs out. The list test uses exactly three across two
     * secrets.
     */
    private function code(string $secret): string
    {
        $google2fa = app(Google2FA::class);
        $step = $google2fa->getTimestamp() - 1 + $this->stepOffset++;

        return $google2fa->oathTotp($secret, $step);
    }

    private function login(array $extra = [], string $email = 'sam@example.test', string $password = self::PASSWORD)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/patient/auth/login', ['email' => $email, 'password' => $password] + $extra);
    }

    /** Sign in with a code and trust the browser; returns the trusted-device token. */
    private function trustedToken(string $secret, string $email = 'sam@example.test'): string
    {
        $challenge = $this->login([], $email)->json('data.challenge');

        return $this->postJson('/api/v1/patient/auth/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret), 'trust_device' => true])
            ->assertOk()
            ->json('data.trusted_device.token');
    }

    public function test_trusting_after_a_code_returns_a_token_stored_only_as_a_hash(): void
    {
        [$patient, $secret] = $this->enrolled();

        $token = $this->trustedToken($secret);

        $this->assertTrue(PatientEmailToken::looksValid($token));
        $device = PatientTrustedDevice::sole();
        $this->assertSame($patient->id, $device->patient_id);
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertSame('Safari on iPhone', $device->label);
        $this->assertTrue($device->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
        $this->assertSame(1, PatientSecurityEvent::where('type', SecurityEventType::DeviceTrusted)->count());
    }

    public function test_a_code_sign_in_without_asking_to_trust_issues_nothing(): void
    {
        [, $secret] = $this->enrolled();
        $challenge = $this->login()->json('data.challenge');

        $this->postJson('/api/v1/patient/auth/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertOk()
            ->assertJsonPath('data.trusted_device', null);

        $this->assertSame(0, PatientTrustedDevice::count());
    }

    public function test_a_trusted_browser_skips_the_code_but_not_the_password(): void
    {
        [$patient, $secret] = $this->enrolled();
        $token = $this->trustedToken($secret);

        $this->login(['trusted_device_token' => $token], 'sam@example.test', 'wrong')->assertUnprocessable();

        $this->travel(3)->days();

        $session = $this->login(['trusted_device_token' => $token])
            ->assertOk()
            ->assertJsonMissingPath('data.two_factor_required')
            ->json('data.token');
        $this->assertNotNull($session);

        $succeeded = PatientSecurityEvent::where('type', SecurityEventType::LoginSucceeded)->latest('id')->first();
        $this->assertSame(['method' => 'trusted_device'], $succeeded->context);
        // Skipping the code is not a fresh second factor.
        $this->assertNull($patient->tokens()->latest('id')->first()->two_factor_verified_at);

        $device = PatientTrustedDevice::sole();
        $this->assertTrue($device->expires_at->greaterThan(now()->addDays(29)), 'Use slides the expiry forward.');
        // …and the renewed expiry is returned, so the browser's cookie can follow it.
        $renewed = $this->login(['trusted_device_token' => $token])->assertOk()->json('data.trusted_device.expires_at');
        $this->assertSame(PatientTrustedDevice::sole()->expires_at->toIso8601String(), $renewed);
        $this->assertTrue(PatientTrustedDevice::sole()->expires_at->greaterThan(now()->addDays(29)));
        $this->assertSame('198.51.100.7', $device->last_used_ip);
    }

    public function test_a_token_from_another_patient_or_garbage_is_ignored(): void
    {
        [, $secretA] = $this->enrolled('a@example.test');
        $this->enrolled('b@example.test');
        $tokenA = $this->trustedToken($secretA, 'a@example.test');

        $this->login(['trusted_device_token' => $tokenA], 'b@example.test')->assertOk()->assertJsonPath('data.two_factor_required', true);
        $this->login(['trusted_device_token' => str_repeat('x', 43)], 'a@example.test')->assertOk()->assertJsonPath('data.two_factor_required', true);
    }

    public function test_trust_expires(): void
    {
        [, $secret] = $this->enrolled();
        $token = $this->trustedToken($secret);

        $this->travel(31)->days();

        $this->login(['trusted_device_token' => $token])->assertOk()->assertJsonPath('data.two_factor_required', true);
    }

    public function test_setting_the_days_to_zero_stops_issuing_and_honouring(): void
    {
        [, $secret] = $this->enrolled();
        $token = $this->trustedToken($secret);

        $settings = app(PortalSettings::class);
        $settings->trusted_device_days = 0;
        $settings->save();

        $this->login(['trusted_device_token' => $token])->assertOk()->assertJsonPath('data.two_factor_required', true);

        $challenge = $this->login()->json('data.challenge');
        $this->postJson('/api/v1/patient/auth/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret), 'trust_device' => true])
            ->assertOk()
            ->assertJsonPath('data.trusted_device', null);
    }

    // ─── Revocation ──────────────────────────────────────────────────

    public function test_a_password_reset_revokes_trust(): void
    {
        [$patient, $secret] = $this->enrolled();
        $token = $this->trustedToken($secret);

        $plain = PatientEmailToken::newPlainToken();
        PatientEmailToken::create([
            'purpose' => PatientEmailToken::PURPOSE_PASSWORD_RESET, 'patient_id' => $patient->id,
            'sent_to' => 'sam@example.test', 'token_hash' => PatientEmailToken::hash($plain), 'expires_at' => now()->addHour(),
        ]);
        $this->postJson('/api/v1/patient/auth/password/reset', ['token' => $plain, 'password' => 'a-brand-new-password'])->assertOk();

        $this->assertSame('password_reset', PatientTrustedDevice::sole()->revoked_reason);
        $this->login(['trusted_device_token' => $token], 'sam@example.test', 'a-brand-new-password')
            ->assertOk()->assertJsonPath('data.two_factor_required', true);
    }

    public function test_turning_two_step_off_revokes_trust(): void
    {
        [$patient, $secret] = $this->enrolled();
        $this->trustedToken($secret);
        $session = $patient->createToken('t', ['patient:*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/patient/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)], ['Authorization' => "Bearer {$session}"])->assertOk();

        $this->assertSame('two_factor_removed', PatientTrustedDevice::sole()->revoked_reason);

        // The patient's own act, so the patient's own device stays on it.
        $revoked = PatientSecurityEvent::where('type', SecurityEventType::DeviceRevoked)->sole();
        $this->assertSame(SecurityEventActor::Patient, $revoked->actor_type);
        $this->assertSame('198.51.100.7', $revoked->ip_address);
    }

    public function test_support_resetting_two_step_revokes_trust_as_the_operator(): void
    {
        [$patient, $secret] = $this->enrolled();
        $this->trustedToken($secret);
        $operator = User::factory()->create();

        app(ResetPatientTwoFactorAction::class)->execute($patient, $operator->id);

        $this->assertSame('two_factor_reset', PatientTrustedDevice::sole()->revoked_reason);
        $revoked = PatientSecurityEvent::where('type', SecurityEventType::DeviceRevoked)->sole();
        $this->assertSame(SecurityEventActor::Operator, $revoked->actor_type);
        $this->assertSame($operator->id, $revoked->actor_user_id);
    }

    public function test_a_first_email_verification_revokes_trust(): void
    {
        [$patient, $secret] = $this->enrolled();
        $this->trustedToken($secret);
        $patient->forceFill(['email_verified_at' => null])->save();
        $lead = Lead::factory()->create(['email' => 'sam@example.test', 'patient_id' => null, 'prescribe_rx_patient_id' => 'chart-1']);
        Encounter::factory()->create(['lead_id' => $lead->id, 'prescribe_rx_patient_id' => 'chart-1']);
        $plain = PatientEmailToken::newPlainToken();
        PatientEmailToken::create([
            'purpose' => PatientEmailToken::PURPOSE_CLAIM, 'lead_id' => $lead->id, 'sent_to' => 'sam@example.test',
            'token_hash' => PatientEmailToken::hash($plain), 'expires_at' => now()->addHour(),
        ]);
        $session = $patient->createToken('t', ['patient:*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/patient/claim', ['token' => $plain], ['Authorization' => "Bearer {$session}"])->assertOk();

        $this->assertSame('first_verification', PatientTrustedDevice::sole()->revoked_reason);
    }

    public function test_sign_out_everywhere_revokes_trust(): void
    {
        [$patient, $secret] = $this->enrolled();
        $token = $this->trustedToken($secret);

        $operator = User::factory()->create();
        app(RevokePatientSessionsAction::class)->execute($patient, $operator->id);

        $this->assertSame('operator', PatientTrustedDevice::sole()->revoked_reason);
        $revoked = PatientSecurityEvent::where('type', SecurityEventType::DeviceRevoked)->sole();
        $this->assertSame(SecurityEventActor::Operator, $revoked->actor_type);
        $this->assertSame($operator->id, $revoked->actor_user_id);
        $this->login(['trusted_device_token' => $token])->assertOk()->assertJsonPath('data.two_factor_required', true);
    }

    public function test_replacing_the_authenticator_revokes_trust(): void
    {
        [$patient, $secret] = $this->enrolled();
        $this->trustedToken($secret);
        $session = $patient->createToken('t', ['patient:*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $newSecret = $this->postJson('/api/v1/patient/two-factor/setup', ['code' => $this->code($secret)], ['Authorization' => "Bearer {$session}"])->assertOk()->json('data.secret');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/patient/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($newSecret)], ['Authorization' => "Bearer {$session}"])->assertOk();

        $this->assertSame('two_factor_replaced', PatientTrustedDevice::sole()->revoked_reason);
    }

    public function test_deleting_the_account_revokes_trust(): void
    {
        [$patient, $secret] = $this->enrolled();
        $this->trustedToken($secret);

        $patient->delete();

        $this->assertSame('account_deleted', PatientTrustedDevice::sole()->revoked_reason);
        // No admin user signed in: the system, with no operator id.
        $revoked = PatientSecurityEvent::where('type', SecurityEventType::DeviceRevoked)->sole();
        $this->assertSame(SecurityEventActor::System, $revoked->actor_type);
        $this->assertNull($revoked->actor_user_id);
    }

    // ─── The patient's own list ──────────────────────────────────────

    public function test_the_patient_lists_and_revokes_their_own_trusted_browsers_only(): void
    {
        [$patient, $secret] = $this->enrolled();
        [, $otherSecret] = $this->enrolled('other@example.test');
        $this->trustedToken($secret);
        $this->trustedToken($secret);
        $this->trustedToken($otherSecret, 'other@example.test');
        $foreign = PatientTrustedDevice::where('patient_id', '!=', $patient->id)->sole();

        $session = $patient->createToken('t', ['patient:*'])->plainTextToken;
        $auth = ['Authorization' => "Bearer {$session}"];
        $this->app['auth']->forgetGuards();

        $list = $this->getJson('/api/v1/patient/trusted-devices', $auth)->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertSame('Safari on iPhone', $list->json('data.0.label'));
        $this->assertStringNotContainsString('token_hash', $list->getContent());

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/patient/trusted-devices/{$foreign->uuid}/revoke", [], $auth)->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/patient/trusted-devices/'.$list->json('data.0.id').'/revoke', [], $auth)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/patient/trusted-devices/revoke-all', [], $auth)->assertOk()->assertJsonPath('data.revoked', 1);

        $this->assertSame(0, $patient->trustedDevices()->active()->count());
        $this->assertSame(1, PatientTrustedDevice::active()->count());
    }

    public function test_labels_are_derived_not_typed(): void
    {
        $this->assertSame('Chrome on Windows', TrustedDevices::label('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'));
        $this->assertSame('Edge on Windows', TrustedDevices::label('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0'));
        $this->assertSame('Unknown browser', TrustedDevices::label('<script>alert(1)</script>'));
        $this->assertNull(TrustedDevices::label(null));
    }
}
