<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Models\Patient;
use App\Models\User;
use App\Services\PrescribeRx\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PatientAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Nothing anonymous may ever resolve a clinical identity by email.
        $this->mock(Client::class, function ($mock) {
            $mock->shouldNotReceive('findPatientByEmail');
        });
    }

    // Registration no longer creates an account — it emails a link, and the
    // link creates it. Covered by AccountLinkRequestTest and CreateAccountTest.

    public function test_login_returns_token_with_valid_credentials(): void
    {
        $patient = Patient::factory()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/v1/patient/auth/login', [
            'email' => $patient->email,
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'patient' => ['uuid', 'email']],
            ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $patient = Patient::factory()->create(['password' => bcrypt('correct')]);

        $this->postJson('/api/v1/patient/auth/login', [
            'email' => $patient->email,
            'password' => 'wrong',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_rejects_unknown_email(): void
    {
        $this->postJson('/api/v1/patient/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'anything',
        ])->assertUnprocessable();
    }

    // ── Me ────────────────────────────────────────────────────────────────

    public function test_me_returns_patient_profile(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('test', ['patient:*'])->plainTextToken;

        $this->getJson('/api/v1/patient/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.email', $patient->email);
    }

    public function test_me_rejects_unauthenticated(): void
    {
        $this->getJson('/api/v1/patient/auth/me')->assertUnauthorized();
    }

    public function test_me_rejects_admin_user_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-token', ['frontend:*'])->plainTextToken;

        $this->getJson('/api/v1/patient/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertUnauthorized();
    }

    // ── Logout ────────────────────────────────────────────────────────────

    public function test_logout_revokes_current_patient_token(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('test', ['patient:*'])->plainTextToken;

        $this->postJson('/api/v1/patient/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertSame(0, $patient->fresh()->tokens()->count());
    }

    /**
     * A 429 on logout left the admin token alive while the portal had already
     * dropped its cookie. Exhaust the per-IP sign-in bucket first, so a logout
     * still counted against it fails here.
     */
    public function test_logout_and_me_are_not_counted_against_the_sign_in_limiter(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('test', ['patient:*'])->plainTextToken;

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/patient/auth/login', ['email' => 'nobody@example.com', 'password' => 'x']);
        }
        $this->postJson('/api/v1/patient/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertTooManyRequests();

        $this->getJson('/api/v1/patient/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->postJson('/api/v1/patient/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertSame(0, $patient->fresh()->tokens()->count());
    }

    /** Over the route table, so a later regroup cannot quietly put them back. */
    public function test_signed_in_account_routes_carry_the_per_account_limiter_and_no_store(): void
    {
        foreach (['api.v1.patient.auth.logout', 'api.v1.patient.auth.me'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertNotContains('throttle:auth', $middleware, $name);
            $this->assertContains('throttle:api', $middleware, $name);
            $this->assertContains('no-store', $middleware, $name);
        }

        foreach (['api.v1.patient.auth.login', 'api.v1.patient.auth.two-factor'] as $name) {
            $this->assertContains('throttle:auth', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    /**
     * Was a 500 "Route [login] not defined": the framework computes a guest
     * redirect eagerly for any request that does not ask for JSON.
     */
    public function test_an_unauthenticated_request_without_a_json_accept_header_is_a_401_not_a_500(): void
    {
        $this->get('/api/v1/patient/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->get('/api/v1/patient/session')->assertUnauthorized();
    }

    /** And a validation failure without the header is a 422, not a 302. */
    public function test_a_validation_failure_without_a_json_accept_header_is_a_422(): void
    {
        $this->post('/api/v1/patient/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
