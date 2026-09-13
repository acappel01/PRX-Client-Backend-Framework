<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Models\Patient;
use App\Models\User;
use App\Services\PrescribeRx\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
