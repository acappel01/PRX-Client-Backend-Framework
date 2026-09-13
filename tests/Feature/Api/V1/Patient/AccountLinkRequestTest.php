<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\RequestAccountLinkAction;
use App\Http\Controllers\Api\V1\Patient\AuthController;
use App\Jobs\Patient\SendAccountLinkJob;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use App\Services\PrescribeRx\Client;
use App\Settings\CommunicationSettings;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Tests\Feature\Api\V1\Patient\Concerns\ClaimFixtures;
use Tests\TestCase;

/**
 * `POST /patient/auth/register` and `POST /patient/auth/password/forgot` —
 * "email me a link", anonymous.
 *
 * Registration creates NOTHING any more. Every test asserts on the mail that
 * reached the transport and the rows written, never on the status alone: the
 * status is the same 202 whatever exists under the address, which is the point.
 * The queue is `sync` in tests, so the job runs inside the request here.
 */
class AccountLinkRequestTest extends TestCase
{
    use ClaimFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->mock(Client::class, fn ($mock) => $mock->shouldNotReceive('findPatientByEmail'));
    }

    private function register(string $email = 'buyer@example.test')
    {
        return $this->postJson('/api/v1/patient/auth/register', ['email' => $email]);
    }

    private function forgot(string $email = 'buyer@example.test')
    {
        return $this->postJson('/api/v1/patient/auth/password/forgot', ['email' => $email]);
    }

    public function test_registering_creates_no_account(): void
    {
        $this->enableClaimMail();
        $this->order();

        $this->postJson('/api/v1/patient/auth/register', [
            'email' => 'buyer@example.test',
            'password' => 'secret-password',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ])->assertStatus(202)
            ->assertJsonPath('message', AuthController::LINK_SENT)
            ->assertJsonMissingPath('data.token');

        $this->assertSame(0, Patient::withTrashed()->count());
    }

    public function test_an_address_with_a_claimable_order_is_sent_a_create_account_link(): void
    {
        $this->enableClaimMail();
        $lead = $this->order(['email' => 'Buyer@Example.test']);

        $this->register('  BUYER@example.test ')->assertStatus(202);

        $this->assertCount(1, $this->sentMail);
        $mail = $this->sentMail[0];
        $this->assertSame('buyer@example.test', $mail->getTo()[0]->getAddress());

        $token = PatientEmailToken::sole();
        $this->assertSame(PatientEmailToken::PURPOSE_CREATE_ACCOUNT, $token->purpose);
        $this->assertSame($lead->id, $token->lead_id);
        $this->assertNull($token->patient_id);
        $this->assertSame('buyer@example.test', $token->sent_to);
        $this->assertSame(PatientEmailToken::hash($this->tokenFrom($mail, 'create-account')), $token->token_hash);
    }

    public function test_an_address_with_an_account_is_sent_a_reset_link_whether_verified_or_not(): void
    {
        $this->enableClaimMail();
        $unverified = Patient::factory()->create(['email' => 'squatted@example.test', 'email_verified_at' => null]);

        $this->register('squatted@example.test')->assertStatus(202);

        $token = PatientEmailToken::sole();
        $this->assertSame(PatientEmailToken::PURPOSE_PASSWORD_RESET, $token->purpose);
        $this->assertSame($unverified->id, $token->patient_id);
        $this->assertNull($token->lead_id);
        $this->tokenFrom($this->sentMail[0], 'reset');
    }

    public function test_forgot_and_register_are_the_same_request(): void
    {
        $this->enableClaimMail();
        Patient::factory()->create(['email' => 'held@example.test']);
        $this->order(['email' => 'new@example.test']);

        $this->forgot('held@example.test')->assertStatus(202);
        $this->forgot('new@example.test')->assertStatus(202);

        $this->assertSame(
            [PatientEmailToken::PURPOSE_PASSWORD_RESET, PatientEmailToken::PURPOSE_CREATE_ACCOUNT],
            PatientEmailToken::orderBy('id')->pluck('purpose')->all(),
        );
    }

    /** THE EMPTY PATH: identical answer, no row, no mail. */
    public function test_an_address_with_nothing_answers_the_same_and_sends_nothing(): void
    {
        $this->enableClaimMail();

        $this->register('nobody@example.test')
            ->assertStatus(202)
            ->assertJsonPath('message', AuthController::LINK_SENT);

        $this->assertSame([], $this->sentMail);
        $this->assertSame(0, PatientEmailToken::count());
    }

    public function test_a_self_minted_lead_earns_no_email(): void
    {
        $this->enableClaimMail();
        $this->order(chartOnEncounter: null);

        $this->register()->assertStatus(202);

        $this->assertSame([], $this->sentMail);
    }

    public function test_an_already_claimed_order_earns_no_create_link(): void
    {
        $this->enableClaimMail();
        $holder = Patient::factory()->create(['email' => 'someone-else@example.test']);
        $this->order(['patient_id' => $holder->id]);

        $this->register()->assertStatus(202);

        $this->assertSame([], $this->sentMail);
    }

    /**
     * A deleted account still holds its unique address. Offering a create link
     * would dead-end at "invalid link", and a reset would revive what an operator
     * removed — so nothing, answered the same.
     */
    public function test_a_deleted_account_earns_nothing(): void
    {
        $this->enableClaimMail();
        $this->order();
        Patient::factory()->create(['email' => 'buyer@example.test'])->delete();

        $this->register()->assertStatus(202);

        $this->assertSame([], $this->sentMail);
        $this->assertSame(0, PatientEmailToken::count());
    }

    /** Nothing anyone typed — the account's name or the order's — reaches the mailbox. */
    public function test_neither_email_carries_anything_a_person_wrote(): void
    {
        $this->enableClaimMail();
        $hostile = 'your card was declined. Call 1-800-000-0000 now';
        $this->order(['first_name' => $hostile, 'last_name' => $hostile]);
        Patient::factory()->create(['email' => 'held@example.test', 'first_name' => $hostile, 'last_name' => $hostile]);

        $this->register('buyer@example.test')->assertStatus(202);
        $this->register('held@example.test')->assertStatus(202);

        $this->assertCount(2, $this->sentMail);
        foreach ($this->sentMail as $mail) {
            $this->assertStringNotContainsString('declined', $mail->getTextBody());
            $this->assertStringNotContainsString('declined', $mail->getSubject());
            $this->assertSame('', $mail->getTo()[0]->getName());
            $this->assertSame('no', $mail->getHeaders()->get('o:tracking-clicks')?->getBodyAsString());
        }
    }

    public function test_a_second_request_supersedes_the_first_link(): void
    {
        $this->enableClaimMail();
        $this->order();

        $this->register()->assertStatus(202);
        $first = $this->tokenFrom($this->sentMail[0], 'create-account');
        $this->register()->assertStatus(202);

        $this->assertSame(1, PatientEmailToken::count());
        $this->assertNotSame(PatientEmailToken::hash($first), PatientEmailToken::sole()->token_hash);
    }

    public function test_a_failed_send_leaves_no_live_link_and_still_answers_the_same(): void
    {
        $this->enableClaimMail(capture: false);
        $this->order();
        Event::listen(
            MessageSending::class,
            fn () => throw new \RuntimeException('transport down'),
        );

        $this->register()->assertStatus(202);

        $this->assertSame(0, PatientEmailToken::count());
    }

    public function test_it_answers_503_when_email_is_switched_off_and_writes_nothing(): void
    {
        $this->enableClaimMail();
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = false;
        $settings->save();
        $this->order();

        $this->register()->assertStatus(503);

        $this->assertSame([], $this->sentMail);
        $this->assertSame(0, PatientEmailToken::count());
    }

    /** The 503 describes the installation: it is the same with or without an order. */
    public function test_the_503_does_not_depend_on_the_address(): void
    {
        $this->enableClaimMail();
        config(['portal.url' => null]);
        $this->order();

        $this->register('buyer@example.test')->assertStatus(503);
        $this->register('nobody@example.test')->assertStatus(503);
    }

    public function test_it_answers_503_when_no_queue_worker_is_running(): void
    {
        $this->enableClaimMail();
        config(['queue.default' => 'redis']);
        $this->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([]));
        Queue::fake();

        $this->register()->assertStatus(503);

        Queue::assertNothingPushed();
    }

    /** The request answers before deciding anything; the job carries no token. */
    public function test_the_decision_is_queued_encrypted_and_carries_no_credential(): void
    {
        $this->enableClaimMail();
        $this->order();
        Queue::fake();

        $this->register(' Buyer@Example.test ')->assertStatus(202);

        $this->assertSame(0, PatientEmailToken::count());
        Queue::assertPushed(SendAccountLinkJob::class, function (SendAccountLinkJob $job): bool {
            $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
            $this->assertSame(1, $job->tries);
            // Nothing minted yet: the credential is created inside the worker.
            $this->assertStringNotContainsString('token', strtolower(implode(',', array_keys(get_object_vars($job)))));

            return $job->email === 'buyer@example.test';
        });
    }

    public function test_malformed_email_is_a_422_that_says_nothing_about_accounts(): void
    {
        $this->enableClaimMail();

        $this->register('not-an-address')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * Keyed by the ADDRESS, normalised before the controller sees it — so case
     * and spaces cannot mint extra buckets for one mailbox.
     */
    public function test_requests_are_limited_per_address_however_it_is_spelled(): void
    {
        $this->enableClaimMail();

        $this->register('limit@example.test')->assertStatus(202);
        $this->register('LIMIT@example.test')->assertStatus(202);
        $this->register(' limit@EXAMPLE.test')->assertStatus(202);
        $this->register('Limit@Example.Test')->assertStatus(429);

        $this->register('other@example.test')->assertStatus(202);
    }

    /** The limiter runs before validation: a non-string body is a 422, not a 500. */
    public function test_a_non_string_email_is_a_422_not_a_500(): void
    {
        $this->enableClaimMail();

        $this->postJson('/api/v1/patient/auth/register', ['email' => ['x@example.test']])->assertStatus(422);
        $this->postJson('/api/v1/patient/auth/register', ['email' => ['a' => 'b']])->assertStatus(422);
    }

    /** A failure inside the job must not write the address into failed_jobs or the log. */
    public function test_a_job_failure_records_no_address(): void
    {
        $this->mock(RequestAccountLinkAction::class, fn ($mock) => $mock->shouldReceive('execute')
            ->andThrow(new \RuntimeException('select * from patients where LOWER(email) = victim@example.test')));
        Log::spy();

        $job = new SendAccountLinkJob('victim@example.test');
        $job->withFakeQueueInteractions();
        $job->handle(app(RequestAccountLinkAction::class));

        $job->assertFailed();
        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context) => ! str_contains($message.json_encode($context), 'victim')
        );
    }

    public function test_normalise_trims_and_lowercases_only(): void
    {
        $this->assertSame('first.last+tag@gmail.com', RequestAccountLinkAction::normalise('  First.Last+Tag@GMAIL.com '));
    }
}
