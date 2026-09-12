<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Events\Patient\ClaimLinkRequested;
use App\Http\Controllers\Api\V1\Patient\AccountController;
use App\Models\Integrations\IntegrationInstance;
use App\Models\PatientEmailToken;
use App\Settings\CommunicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\V1\Patient\Concerns\ClaimFixtures;
use Tests\TestCase;

/**
 * `POST /patient/claim-links` — emailing the link that proves an order's mailbox.
 *
 * The refusals are all SILENT on purpose: the response must not tell a caller
 * which addresses have orders. So every "nothing was sent" test asserts on the
 * mail that actually left and the rows that were written, never on the status
 * alone — a 202 is what every one of them gets.
 */
class RequestClaimLinkTest extends TestCase
{
    use ClaimFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_a_link_is_emailed_to_the_address_the_order_was_placed_under(): void
    {
        $this->enableClaimMail();
        $this->actingAsPatient(['email' => 'Buyer@Example.test']);
        $this->order(['email' => 'buyer@example.test']);

        $this->postJson('/api/v1/patient/claim-links')
            ->assertStatus(202)
            ->assertJsonPath('message', AccountController::LINK_SENT);

        $this->assertCount(1, $this->sentMail);
        $mail = $this->sentMail[0];

        // The LEAD's address, not the account's spelling of it.
        $this->assertSame('buyer@example.test', $mail->getTo()[0]->getAddress());

        $token = PatientEmailToken::sole();
        $this->assertSame('buyer@example.test', $token->sent_to);
        $this->assertSame(PatientEmailToken::hash($this->tokenFrom($mail)), $token->token_hash);
        $this->assertNull($token->consumed_at);
    }

    public function test_the_plain_token_is_never_stored(): void
    {
        $this->enableClaimMail();
        $this->actingAsPatient();
        $this->order();

        $plain = $this->requestLinkAndReadToken();

        $this->assertDatabaseMissing('patient_email_tokens', ['token_hash' => $plain]);
        $this->assertStringNotContainsString($plain, json_encode(PatientEmailToken::sole()->getAttributes()));
    }

    public function test_the_email_carries_the_link_and_nothing_that_identifies_the_order(): void
    {
        $this->enableClaimMail();
        $this->actingAsPatient();
        $lead = $this->order();

        $this->requestLinkAndReadToken();

        $body = $this->sentMail[0]->getTextBody();

        $this->assertStringContainsString('https://portal.example.test/claim/', $body);
        $this->assertStringNotContainsString($lead->uuid, $body);
        $this->assertStringNotContainsString(self::CHART, $body);
        $this->assertStringNotContainsString('buyer@example.test', $body);
    }

    public function test_nothing_the_account_holder_wrote_reaches_the_order_mailbox(): void
    {
        // 🔴 Found in review. The account asking may be a stranger who registered
        // the customer's address — registration verifies nothing — and this
        // email goes to the CUSTOMER. A greeting from the account's own name let
        // that stranger write into an email from the brand's verified domain.
        $this->enableClaimMail();
        $hostile = 'your card was declined. Call 1-800-000-0000 now';
        $this->actingAsPatient(['first_name' => $hostile, 'last_name' => $hostile]);
        $this->order();

        $this->requestLinkAndReadToken();

        $mail = $this->sentMail[0];
        $this->assertStringNotContainsString('declined', $mail->getTextBody());
        $this->assertStringNotContainsString('declined', $mail->getSubject());
        $this->assertSame('', $mail->getTo()[0]->getName());
    }

    public function test_link_tracking_is_switched_off_for_this_message(): void
    {
        // Mailgun's click tracking rewrites every link through its own
        // redirector — plain HTTP on this install's domain. Off in the dashboard
        // today; this makes it off regardless of the dashboard.
        $this->enableClaimMail();
        $this->actingAsPatient();
        $this->order();

        $this->requestLinkAndReadToken();

        $headers = $this->sentMail[0]->getHeaders();
        $this->assertSame('no', $headers->get('o:tracking-clicks')?->getBodyAsString());
        $this->assertSame('no', $headers->get('o:tracking-opens')?->getBodyAsString());
    }

    public function test_no_eligible_order_answers_the_same_and_sends_nothing(): void
    {
        // THE EMPTY PATH. Identical response, zero rows, zero mail — and the
        // assertion is on mail that reached the transport, so it cannot pass
        // because a fake swallowed the send.
        Event::fake([ClaimLinkRequested::class]);
        $this->enableClaimMail();
        $this->actingAsPatient();

        $this->postJson('/api/v1/patient/claim-links')
            ->assertStatus(202)
            ->assertJsonPath('message', AccountController::LINK_SENT);

        $this->assertSame([], $this->sentMail);
        $this->assertSame(0, PatientEmailToken::count());
        Event::assertNotDispatched(ClaimLinkRequested::class);
    }

    public function test_a_self_minted_lead_earns_no_email(): void
    {
        // `POST /leads` is anonymous. Without the encounter requirement here,
        // anyone could make us email any address.
        $this->enableClaimMail();
        $this->actingAsPatient();
        $this->order(chartOnEncounter: null);

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);

        $this->assertSame([], $this->sentMail);
        $this->assertSame(0, PatientEmailToken::count());
    }

    public function test_an_order_under_a_different_address_earns_no_email(): void
    {
        $this->enableClaimMail();
        $this->actingAsPatient(['email' => 'attacker@example.test']);
        $this->order(['email' => 'victim@example.test']);

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);

        $this->assertSame([], $this->sentMail);
    }

    public function test_an_already_claimed_order_earns_no_email(): void
    {
        $this->enableClaimMail();
        $this->actingAsPatient();
        $other = \App\Models\Patient::factory()->create(['email' => 'other@example.test']);
        $this->order(['patient_id' => $other->id]);

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);

        $this->assertSame([], $this->sentMail);
    }

    public function test_a_second_request_supersedes_the_first_link(): void
    {
        $this->enableClaimMail();
        $this->actingAsPatient();
        $this->order();

        $first = $this->requestLinkAndReadToken();
        $second = $this->requestLinkAndReadToken();

        $this->assertNotSame($first, $second);
        $this->assertSame(1, PatientEmailToken::count());
        $this->assertSame(PatientEmailToken::hash($second), PatientEmailToken::sole()->token_hash);
    }

    public function test_it_answers_503_when_email_is_switched_off_and_writes_nothing(): void
    {
        $this->enableClaimMail();
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = false;
        $settings->save();

        $this->actingAsPatient();
        $this->order();

        // An honest refusal, not a 202 promising a message that cannot leave.
        $this->postJson('/api/v1/patient/claim-links')->assertStatus(503);

        $this->assertSame([], $this->sentMail);
        $this->assertSame(0, PatientEmailToken::count());
    }

    public function test_the_503_does_not_depend_on_whether_an_order_exists(): void
    {
        // Checked BEFORE eligibility. Otherwise "503 means you have an order"
        // would be the enumeration the uniform 202 exists to prevent.
        $this->enableClaimMail();
        IntegrationInstance::query()->delete();
        $this->actingAsPatient();

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(503);
    }

    public function test_it_answers_503_when_no_integration_can_send_transactional_email(): void
    {
        $this->enableClaimMail();
        IntegrationInstance::query()->update(['capabilities' => ['crm']]);
        $this->actingAsPatient();
        $this->order();

        $this->postJson('/api/v1/patient/claim-links')
            ->assertStatus(503)
            // Written for the patient — not the operator's "configure one under
            // Integrations".
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('message', 'We cannot send email right now. Please try again later or contact support.');
    }

    public function test_it_answers_503_when_the_portal_url_is_not_configured(): void
    {
        $this->enableClaimMail();
        config(['portal.url' => null]);
        $this->actingAsPatient();
        $this->order();

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(503);

        $this->assertSame([], $this->sentMail);
    }

    public function test_a_failed_send_leaves_no_live_link_and_says_so(): void
    {
        // The transport passed its check and then failed on the send itself.
        // A link nobody received must not stay live, and the patient must not
        // be told it was sent.
        Event::fake([ClaimLinkRequested::class]);
        // No capture listener: it would cancel the send before this one throws.
        $this->enableClaimMail(capture: false);
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, function (): void {
            throw new \RuntimeException('transport exploded');
        });
        $this->actingAsPatient();
        $this->order();

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(503);

        $this->assertSame(0, PatientEmailToken::count());
        Event::assertNotDispatched(ClaimLinkRequested::class);
    }

    public function test_the_token_free_event_fires_when_a_link_is_sent(): void
    {
        Event::fake([ClaimLinkRequested::class]);
        $this->enableClaimMail();
        $patient = $this->actingAsPatient();
        $this->order();

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);

        Event::assertDispatched(ClaimLinkRequested::class, function (ClaimLinkRequested $event) use ($patient): bool {
            // A workflow trigger. It must carry the patient and nothing else —
            // context is serialised into a job and written to the run log.
            return $event->patient->is($patient)
                && array_keys(get_object_vars($event)) === ['patient'];
        });
    }

    public function test_requests_are_rate_limited_per_account(): void
    {
        $this->enableClaimMail();
        $this->actingAsPatient();
        $this->order();

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);
        }

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(429);
        $this->assertCount(3, $this->sentMail);

        // Keyed by ACCOUNT, not IP: a second account behind the same address
        // (a household, an office NAT) still gets its own three.
        $this->actingAsPatient(['email' => 'second@example.test']);
        $this->order(['email' => 'second@example.test'], chartOnEncounter: 'another-chart');

        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);
        $this->assertCount(4, $this->sentMail);
    }

    public function test_it_requires_a_patient_session(): void
    {
        $this->postJson('/api/v1/patient/claim-links')->assertUnauthorized();
    }

    public function test_the_old_uuid_endpoint_is_gone(): void
    {
        // Its checks were a strict subset of the claim's. Left reachable, it
        // would have kept the unverified path open.
        $this->actingAsPatient();
        $lead = $this->order();

        $this->postJson('/api/v1/patient/link-chart', ['lead_uuid' => $lead->uuid])->assertNotFound();
    }
}
