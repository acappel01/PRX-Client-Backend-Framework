<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\ClaimPatientRecordAction;
use App\Events\Patient\AccountCreated;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\RecordClaimed;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use App\Services\PrescribeRx\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\V1\Patient\Concerns\ClaimFixtures;
use Tests\TestCase;

/**
 * `POST /patient/auth/create-account` — the link creates the account.
 *
 * The account's address is the one the link went to, never a request field; it
 * is verified and connected to its order's record in the same transaction; and
 * one address can only ever hold one account.
 */
class CreateAccountTest extends TestCase
{
    use ClaimFixtures, RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->mock(Client::class, fn ($mock) => $mock->shouldNotReceive('findPatientByEmail'));
    }

    /** Mint a create-account token directly and return the plain value. */
    private function tokenFor(Lead $lead, array $overrides = []): string
    {
        $plain = PatientEmailToken::newPlainToken();

        PatientEmailToken::create(array_merge([
            'purpose' => PatientEmailToken::PURPOSE_CREATE_ACCOUNT,
            'lead_id' => $lead->id,
            'sent_to' => strtolower($lead->email),
            'token_hash' => PatientEmailToken::hash($plain),
            'expires_at' => now()->addHour(),
        ], $overrides));

        return $plain;
    }

    private function create(string $token, array $extra = [])
    {
        return $this->postJson('/api/v1/patient/auth/create-account', array_merge([
            'token' => $token,
            'password' => self::PASSWORD,
        ], $extra));
    }

    public function test_the_link_creates_a_verified_account_connected_to_its_record(): void
    {
        Event::fake([AccountCreated::class, RecordClaimed::class, EmailVerified::class]);
        $lead = $this->order(['email' => 'Buyer@Example.test', 'first_name' => 'Ada', 'last_name' => 'Byron']);
        $token = $this->tokenFor($lead);

        $this->create($token)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'patient' => ['uuid', 'email']]]);

        $patient = Patient::sole();
        $this->assertSame('buyer@example.test', $patient->email);
        $this->assertNotNull($patient->email_verified_at);
        $this->assertSame(self::CHART, $patient->prx_patient_chart_id);
        $this->assertNotNull($patient->prx_chart_verified_at);
        $this->assertSame('Ada', $patient->first_name);
        $this->assertTrue(Hash::check(self::PASSWORD, $patient->password));
        $this->assertSame($patient->id, $lead->refresh()->patient_id);

        $row = PatientEmailToken::sole();
        $this->assertNotNull($row->consumed_at);
        $this->assertSame($patient->id, $row->consumed_by_patient_id);

        $this->assertSame(['patient:*'], $patient->tokens()->sole()->abilities);

        foreach ([AccountCreated::class, RecordClaimed::class, EmailVerified::class] as $event) {
            Event::assertDispatched($event, fn ($e) => $e->patient->is($patient));
        }
    }

    public function test_the_session_it_returns_works(): void
    {
        $token = $this->tokenFor($this->order());

        $session = $this->create($token)->json('data.token');

        $this->withToken($session)->getJson('/api/v1/patient/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'buyer@example.test');
    }

    /** The address comes from the token. A request field cannot choose it. */
    public function test_an_email_in_the_request_is_ignored(): void
    {
        $token = $this->tokenFor($this->order());

        $this->create($token, ['email' => 'attacker@example.test'])->assertCreated();

        $this->assertSame('buyer@example.test', Patient::sole()->email);
        $this->assertFalse(Patient::where('email', 'attacker@example.test')->exists());
    }

    public function test_a_link_works_once(): void
    {
        $token = $this->tokenFor($this->order());

        $this->create($token)->assertCreated();
        $this->create($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL_ANONYMOUS);

        $this->assertSame(1, Patient::count());
    }

    public function test_an_expired_link_creates_nothing(): void
    {
        $token = $this->tokenFor($this->order(), ['expires_at' => now()->subMinute()]);

        $this->create($token)->assertStatus(422)->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL_ANONYMOUS);

        $this->assertSame(0, Patient::count());
    }

    /** One account per address, whatever the case, including a deleted one. */
    public function test_an_address_that_already_has_an_account_gets_no_second_one(): void
    {
        $lead = $this->order();
        $existing = Patient::factory()->create(['email' => 'BUYER@example.test']);

        $this->create($this->tokenFor($lead))->assertStatus(422)->assertJsonPath('errors.token.0', ClaimPatientRecordAction::REFUSAL_ANONYMOUS);

        $existing->delete();
        $this->create($this->tokenFor($lead))->assertStatus(422);

        $this->assertSame(1, Patient::withTrashed()->count());
        $this->assertNull(PatientEmailToken::whereNotNull('consumed_at')->first(), 'A refusal must roll the consumption back.');
    }

    public function test_an_order_claimed_since_the_link_went_out_creates_nothing(): void
    {
        $lead = $this->order();
        $token = $this->tokenFor($lead);
        $holder = Patient::factory()->create(['email' => 'holder@example.test']);
        $lead->forceFill(['patient_id' => $holder->id])->save();

        $this->create($token)->assertStatus(422);

        $this->assertSame(1, Patient::count());
    }

    /**
     * No chart on the encounter yet: the link refuses, and the account and the
     * consumption roll back, so the same link works once the provider catches up.
     */
    public function test_a_link_refusal_rolls_back_the_account_and_keeps_the_link_usable(): void
    {
        $lead = $this->order(chartOnEncounter: null);
        $token = $this->tokenFor($lead);

        $this->create($token)->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertSame(0, Patient::withTrashed()->count());
        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }

    public function test_a_link_spent_between_the_read_and_the_write_creates_nothing(): void
    {
        $token = $this->tokenFor($this->order());

        PatientEmailToken::retrieved(function (PatientEmailToken $row): void {
            DB::table('patient_email_tokens')->where('id', $row->id)->update(['consumed_at' => now()]);
        });

        $this->create($token)->assertStatus(422);

        $this->assertSame(0, Patient::count());
    }

    /** A reset or claim token can never be spent as a create-account token. */
    public function test_a_token_for_another_purpose_is_refused(): void
    {
        $lead = $this->order();

        foreach ([PatientEmailToken::PURPOSE_CLAIM, PatientEmailToken::PURPOSE_PASSWORD_RESET] as $purpose) {
            $this->create($this->tokenFor($lead, ['purpose' => $purpose]))->assertStatus(422);
        }

        $this->assertSame(0, Patient::count());
    }

    public function test_the_password_must_be_eight_characters(): void
    {
        $token = $this->tokenFor($this->order());

        $this->create($token, ['password' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }

    public function test_there_is_no_get_route_a_mail_scanner_could_spend_a_token_on(): void
    {
        $token = $this->tokenFor($this->order());

        $this->getJson("/api/v1/patient/auth/create-account/{$token}")->assertNotFound();
        $this->getJson('/api/v1/patient/auth/create-account?token='.$token)->assertStatus(405);

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
    }

    /** End to end: ask with only an address, receive the link, use it. */
    public function test_register_then_the_emailed_link_creates_the_account(): void
    {
        $this->enableClaimMail();
        $this->order();

        $this->postJson('/api/v1/patient/auth/register', ['email' => 'buyer@example.test'])->assertStatus(202);
        $this->assertSame(0, Patient::count());

        $this->create($this->tokenFrom(end($this->sentMail), 'create-account'))->assertCreated();

        $this->assertSame(self::CHART, Patient::sole()->prx_patient_chart_id);
    }
}
