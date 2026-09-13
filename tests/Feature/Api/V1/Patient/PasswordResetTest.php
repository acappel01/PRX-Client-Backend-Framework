<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\ClaimPatientRecordAction;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\PasswordChanged;
use App\Http\Controllers\Api\V1\Patient\AuthController;
use App\Jobs\Patient\SendPasswordChangedNoticeJob;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\V1\Patient\Concerns\ClaimFixtures;
use Tests\TestCase;

/**
 * `POST /patient/auth/password/reset` — a new password by emailed link.
 *
 * Bound to the account and the address it was sent to; signs every session out;
 * verifies the address (which is how a real owner takes back an unverified,
 * squatted account); never signs anyone in.
 */
class PasswordResetTest extends TestCase
{
    use ClaimFixtures, RefreshDatabase;

    private const PASSWORD = 'a-brand-new-password';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function tokenFor(Patient $patient, array $overrides = []): string
    {
        $plain = PatientEmailToken::newPlainToken();

        PatientEmailToken::create(array_merge([
            'purpose' => PatientEmailToken::PURPOSE_PASSWORD_RESET,
            'patient_id' => $patient->id,
            'sent_to' => strtolower($patient->email),
            'token_hash' => PatientEmailToken::hash($plain),
            'expires_at' => now()->addHour(),
        ], $overrides));

        return $plain;
    }

    private function reset(string $token, string $password = self::PASSWORD)
    {
        return $this->postJson('/api/v1/patient/auth/password/reset', ['token' => $token, 'password' => $password]);
    }

    public function test_it_sets_the_password_and_signs_every_session_out_without_signing_in(): void
    {
        $patient = Patient::factory()->create(['password' => 'old-password', 'email_verified_at' => now()]);
        $patient->createToken('phone');
        $patient->createToken('laptop');

        $this->reset($this->tokenFor($patient))
            ->assertOk()
            ->assertJsonPath('message', AuthController::PASSWORD_CHANGED)
            ->assertJsonMissingPath('data.token');

        $patient->refresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $patient->password));
        $this->assertSame(0, $patient->tokens()->count());
        $this->assertNotNull(PatientEmailToken::sole()->consumed_at);
    }

    public function test_the_new_password_signs_in_and_the_old_one_does_not(): void
    {
        $patient = Patient::factory()->create(['email' => 'p@example.test', 'password' => 'old-password']);

        $this->reset($this->tokenFor($patient))->assertOk();

        $this->postJson('/api/v1/patient/auth/login', ['email' => 'p@example.test', 'password' => 'old-password'])->assertStatus(422);
        $this->postJson('/api/v1/patient/auth/login', ['email' => 'p@example.test', 'password' => self::PASSWORD])->assertOk();
    }

    /**
     * The squatter case: an account someone registered with a stranger's
     * address. The mailbox owner resets it and it is theirs — verified, and the
     * squatter's sessions gone.
     */
    public function test_a_reset_takes_back_an_unverified_account_and_verifies_it(): void
    {
        Event::fake([PasswordChanged::class, EmailVerified::class]);
        $squatted = Patient::factory()->create(['email_verified_at' => null]);
        $squatted->createToken('squatter');

        $this->reset($this->tokenFor($squatted))->assertOk();

        $this->assertNotNull($squatted->refresh()->email_verified_at);
        $this->assertSame(0, $squatted->tokens()->count());
        Event::assertDispatched(PasswordChanged::class, fn ($e) => $e->patient->is($squatted));
        Event::assertDispatched(EmailVerified::class, fn ($e) => $e->patient->is($squatted));
    }

    public function test_an_already_verified_account_does_not_fire_email_verified_again(): void
    {
        Event::fake([PasswordChanged::class, EmailVerified::class]);
        $patient = Patient::factory()->create(['email_verified_at' => now()->subDay()]);

        $this->reset($this->tokenFor($patient))->assertOk();

        Event::assertDispatched(PasswordChanged::class);
        Event::assertNotDispatched(EmailVerified::class);
    }

    /** The chart an operator linked stays; the reset only proves the mailbox. */
    public function test_a_linked_record_survives_the_reset(): void
    {
        $patient = Patient::factory()->create(['email_verified_at' => null, 'prx_patient_chart_id' => self::CHART]);

        $this->reset($this->tokenFor($patient))->assertOk();

        $this->assertSame(self::CHART, $patient->refresh()->prx_patient_chart_id);
    }

    public function test_a_link_works_once_and_other_reset_links_die_with_it(): void
    {
        $patient = Patient::factory()->create();
        $first = $this->tokenFor($patient);
        $second = $this->tokenFor($patient);

        $this->reset($first)->assertOk();

        $this->reset($first)->assertStatus(422)->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL_ANONYMOUS);
        $this->reset($second)->assertStatus(422);
    }

    public function test_an_expired_link_changes_nothing(): void
    {
        $patient = Patient::factory()->create(['password' => 'old-password']);

        $this->reset($this->tokenFor($patient, ['expires_at' => now()->subMinute()]))->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $patient->refresh()->password));
    }

    /** An operator moved the account to another address after the link went out. */
    public function test_a_link_sent_to_an_address_the_account_no_longer_has_is_refused(): void
    {
        $patient = Patient::factory()->create(['email' => 'old@example.test', 'password' => 'old-password']);
        $token = $this->tokenFor($patient);
        $patient->forceFill(['email' => 'new@example.test'])->save();

        $this->reset($token)->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $patient->refresh()->password));
    }

    public function test_a_deleted_account_cannot_be_reset(): void
    {
        $patient = Patient::factory()->create();
        $token = $this->tokenFor($patient);
        $patient->delete();

        $this->reset($token)->assertStatus(422);
    }

    public function test_a_token_for_another_purpose_is_refused(): void
    {
        $patient = Patient::factory()->create(['password' => 'old-password']);

        foreach ([PatientEmailToken::PURPOSE_CLAIM, PatientEmailToken::PURPOSE_CREATE_ACCOUNT] as $purpose) {
            $this->reset($this->tokenFor($patient, ['purpose' => $purpose]))->assertStatus(422);
        }

        $this->assertTrue(Hash::check('old-password', $patient->refresh()->password));
    }

    /** And a reset token cannot be spent as a create-account or claim token. */
    public function test_a_reset_token_cannot_create_an_account_or_claim(): void
    {
        $lead = $this->order();
        $patient = Patient::factory()->create(['email' => 'buyer@example.test', 'prx_patient_chart_id' => null]);
        $token = $this->tokenFor($patient, ['lead_id' => $lead->id]);

        $this->postJson('/api/v1/patient/auth/create-account', ['token' => $token, 'password' => self::PASSWORD])->assertStatus(422);

        Sanctum::actingAs($patient, ['*']);
        $this->postJson('/api/v1/patient/claim', ['token' => $token])->assertStatus(422);

        $this->assertNull($patient->refresh()->prx_patient_chart_id);
        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }

    public function test_a_link_spent_between_the_read_and_the_write_changes_nothing(): void
    {
        $patient = Patient::factory()->create(['password' => 'old-password']);
        $token = $this->tokenFor($patient);

        PatientEmailToken::retrieved(function (PatientEmailToken $row): void {
            DB::table('patient_email_tokens')->where('id', $row->id)->update(['consumed_at' => now()]);
        });

        $this->reset($token)->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $patient->refresh()->password));
    }

    public function test_the_password_must_be_eight_characters(): void
    {
        $patient = Patient::factory()->create();
        $token = $this->tokenFor($patient);

        $this->reset($token, 'short')->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }

    public function test_the_owner_is_told_their_password_changed_with_no_token_and_nothing_anyone_wrote(): void
    {
        $this->enableClaimMail();
        $patient = Patient::factory()->create([
            'email' => 'owner@example.test',
            'first_name' => 'your card was declined. Call 1-800-000-0000 now',
        ]);

        $this->reset($this->tokenFor($patient))->assertOk();

        $this->assertCount(1, $this->sentMail);
        $mail = $this->sentMail[0];
        $this->assertSame('owner@example.test', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('https://portal.example.test/forgot', $mail->getTextBody());
        $this->assertStringNotContainsString('declined', $mail->getTextBody());
        $this->assertDoesNotMatchRegularExpression('#/(reset|claim|create-account)/[A-Za-z0-9_-]{43}#', $mail->getTextBody());
    }

    public function test_the_notice_is_queued_after_the_change_not_part_of_it(): void
    {
        Queue::fake();
        $patient = Patient::factory()->create();

        $this->reset($this->tokenFor($patient))->assertOk();

        Queue::assertPushed(SendPasswordChangedNoticeJob::class, fn ($job) => $job->patientId === $patient->id);
    }

    /** End to end: forgot with only an address, receive the link, use it. */
    public function test_forgot_then_the_emailed_link_resets_the_password(): void
    {
        $this->enableClaimMail();
        $patient = Patient::factory()->create(['email' => 'p@example.test', 'password' => 'old-password']);

        $this->postJson('/api/v1/patient/auth/password/forgot', ['email' => 'P@example.test'])->assertStatus(202);

        $this->reset($this->tokenFrom($this->sentMail[0], 'reset'))->assertOk();

        $this->assertTrue(Hash::check(self::PASSWORD, $patient->refresh()->password));
    }

    public function test_there_is_no_get_route_a_mail_scanner_could_spend_a_token_on(): void
    {
        $token = $this->tokenFor(Patient::factory()->create());

        $this->getJson("/api/v1/patient/auth/password/reset/{$token}")->assertNotFound();
        $this->getJson('/api/v1/patient/auth/password/reset?token='.$token)->assertStatus(405);

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }
}
