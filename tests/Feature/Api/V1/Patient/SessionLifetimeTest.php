<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use App\Models\User;
use App\Services\Patient\PatientSessionLifetime;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Patient sessions end after the idle limit and the absolute cap (defaults
 * 30 minutes / 12 hours). Before this, a patient token never expired.
 */
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function signIn(): array
    {
        $patient = Patient::factory()->create(['email' => 'sam@example.test', 'password' => 'correct-horse']);

        $token = $this->postJson('/api/v1/patient/auth/login', ['email' => 'sam@example.test', 'password' => 'correct-horse'])
            ->assertOk()
            ->json('data.token');

        return [$patient, $token];
    }

    private function me(string $token)
    {
        // A fresh application per call, as separate HTTP requests are: the auth
        // guard caches the user it resolved.
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/patient/auth/me', ['Authorization' => "Bearer {$token}"]);
    }

    public function test_a_new_session_is_stamped_with_the_absolute_cap(): void
    {
        $this->freezeSecond();

        [$patient] = $this->signIn();

        $this->assertTrue($patient->tokens()->sole()->expires_at->equalTo(now()->addHours(12)));
    }

    public function test_a_session_unused_past_the_idle_limit_ends_once_and_is_recorded(): void
    {
        [$patient, $token] = $this->signIn();

        $this->travel(31)->minutes();

        $this->me($token)->assertUnauthorized();
        $this->me($token)->assertUnauthorized();

        $this->assertSame(0, $patient->tokens()->count());

        $expired = PatientSecurityEvent::where('type', SecurityEventType::SessionExpired)->sole();
        $this->assertSame($patient->id, $expired->patient_id);
        $this->assertSame(SecurityEventActor::System, $expired->actor_type);
        $this->assertSame(['reason' => 'idle'], $expired->context);
        $this->assertNotNull($expired->token_id);
    }

    public function test_using_a_session_keeps_it_alive_past_the_idle_limit(): void
    {
        [, $token] = $this->signIn();

        $this->travel(20)->minutes();
        $this->me($token)->assertOk();

        $this->travel(20)->minutes();
        $this->me($token)->assertOk();

        $this->travel(29)->minutes();
        $this->me($token)->assertOk();
    }

    public function test_an_active_session_still_ends_at_the_cap(): void
    {
        [$patient, $token] = $this->signIn();

        // Used every 25 minutes, so never idle: 28 × 25 min = 11 h 40.
        for ($i = 0; $i < 28; $i++) {
            $this->travel(25)->minutes();
            $this->me($token)->assertOk();
        }

        // 12 h 05 after sign-in, 25 minutes after the last use.
        $this->travel(25)->minutes();
        $this->me($token)->assertUnauthorized();

        $this->assertSame(['reason' => 'max_age'], PatientSecurityEvent::where('type', SecurityEventType::SessionExpired)->sole()->context);
        $this->assertSame(0, $patient->tokens()->count());
    }

    /** A cap that ignored expires_at: shortening the setting reaches sessions already issued. */
    public function test_shortening_the_settings_applies_to_live_sessions(): void
    {
        [, $token] = $this->signIn();

        $settings = app(PortalSettings::class);
        $settings->session_idle_minutes = 10;
        $settings->save();

        $this->travel(11)->minutes();

        $this->me($token)->assertUnauthorized();
    }

    public function test_lengthening_the_cap_does_not_extend_a_session_already_issued(): void
    {
        $this->freezeSecond();
        [, $token] = $this->signIn();
        $cap = now()->addHours(12)->toIso8601String();

        $settings = app(PortalSettings::class);
        $settings->session_max_hours = 48;
        $settings->save();
        $this->assertSame(48, app(PortalSettings::class)->refresh()->session_max_hours);

        // The deadline the portal is told is the one that will be enforced.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/patient/session', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.expires_at', $cap);

        // Kept active: 28 × 25 min = 11 h 40, every request accepted.
        for ($i = 0; $i < 28; $i++) {
            $this->travel(25)->minutes();
            $this->me($token)->assertOk();
        }

        // 12 h 05: past the cap stamped at sign-in, inside the new 48 h.
        $this->travel(25)->minutes();
        $this->me($token)->assertUnauthorized();
        $this->assertSame(['reason' => 'max_age'], PatientSecurityEvent::where('type', SecurityEventType::SessionExpired)->sole()->context);
    }

    public function test_a_refused_request_does_not_extend_the_session(): void
    {
        [$patient, $token] = $this->signIn();
        $tokenId = $patient->tokens()->sole()->id;

        $this->travel(31)->minutes();
        $this->me($token)->assertUnauthorized();

        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    /** The storefront's machine tokens must never idle out. */
    public function test_tokens_that_are_not_patient_sessions_are_left_to_sanctum(): void
    {
        $user = User::factory()->create();
        $user->createToken('frontend', ['frontend:*']);
        $token = PersonalAccessToken::sole();
        $token->forceFill(['created_at' => now()->subYear(), 'last_used_at' => now()->subMonth()])->save();

        $lifetime = app(PatientSessionLifetime::class);

        $this->assertTrue($lifetime->allows($token, true));
        $this->assertFalse($lifetime->allows($token, false));
        $this->assertNotNull(PersonalAccessToken::find($token->id));
    }

    public function test_the_session_endpoint_reports_deadlines_and_counts_as_use(): void
    {
        $this->freezeSecond();
        [, $token] = $this->signIn();
        $signedInAt = now()->copy();

        $this->travel(20)->minutes();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/patient/session', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.idle_minutes', 30)
            ->assertJsonPath('data.idle_expires_at', now()->addMinutes(30)->toIso8601String())
            ->assertJsonPath('data.expires_at', $signedInAt->copy()->addHours(12)->toIso8601String());

        // 20 + 25 = 45 minutes after sign-in, 25 after the keep-alive.
        $this->travel(25)->minutes();
        $this->me($token)->assertOk();
    }

    public function test_the_idle_limit_is_published_in_config_for_the_warning(): void
    {
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.portal.session.idle_minutes', 30)
            ->assertJsonPath('data.portal.session.max_hours', 12);
    }
}
