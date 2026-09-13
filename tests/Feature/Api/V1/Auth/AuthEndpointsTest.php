<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        // Assign a role so the user is valid (canAccessPanel checks roles exist).
        $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'user' => ['id', 'name', 'email']],
            ])
            ->assertJsonPath('data.token_type', 'Bearer');
    }

    public function test_login_rejects_invalid_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_rejects_inactive_user(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret123'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertForbidden();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test', ['frontend:*'])->plainTextToken;

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $user->createToken('test', ['frontend:*']);

        $this->assertSame(1, $user->tokens()->count());

        // Re-resolve the token so the request handler has a current access token.
        $token = $user->createToken('test2', ['frontend:*'])->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // The specific token used for logout should be gone; first token unaffected.
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    /** A 429 on logout left the token alive after the client had discarded it. */
    public function test_logout_still_works_after_the_sign_in_limiter_is_exhausted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['frontend:*'])->plainTextToken;

        for ($i = 0; $i < 11; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertTooManyRequests();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->assertContains('throttle:auth', Route::getRoutes()->getByName('api.v1.auth.login')->gatherMiddleware());
        $this->assertNotContains('throttle:auth', Route::getRoutes()->getByName('api.v1.auth.logout')->gatherMiddleware());
    }

    /** Operators and patients are separate tables: equal ids must not share a bucket. */
    public function test_the_general_limiter_keeps_operators_and_patients_with_the_same_id_apart(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $limiter = RateLimiter::limiter('api');

        $keyFor = function ($account) use ($limiter): string {
            $request = request();
            $request->setUserResolver(fn () => $account);

            return $limiter($request)->key;
        };

        $patient->forceFill(['id' => $user->id])->save();

        $this->assertNotSame($keyFor($user), $keyFor($patient->fresh()));
    }

    /** Was a 500: `getRoleNames()` exists only on operators. */
    public function test_a_patient_token_cannot_use_the_operator_account_routes(): void
    {
        $patient = Patient::factory()->create();
        $token = $patient->createToken('test', ['patient:*'])->plainTextToken;

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->assertSame(1, $patient->fresh()->tokens()->count());
    }
}
