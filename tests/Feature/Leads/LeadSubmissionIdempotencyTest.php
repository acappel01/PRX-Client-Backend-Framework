<?php

namespace Tests\Feature\Leads;

use App\Actions\Attribution\RecordCanonicalEventAction;
use App\Actions\Leads\SubmitLeadAction;
use App\Actions\Referral\RecordReferralClickAction;
use App\Data\Leads\LeadData;
use App\Data\Leads\LeadSubmissionData;
use App\Enums\Quiz\QuizQuestionKind;
use App\Events\Leads\LeadCreated;
use App\Events\Quiz\QuizCompleted;
use App\Models\Lead;
use App\Models\Quiz\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class LeadSubmissionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([LeadCreated::class, QuizCompleted::class]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['first_name' => 'Casey', 'last_name' => 'Example', 'email' => 'casey@example.test'];
    }

    private function headers(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'X-Lead-Submission-Secret' => bin2hex(random_bytes(32))];
    }

    public function test_identical_retry_freezes_response_and_records_consent_events_and_workflow_once(): void
    {
        $headers = $this->headers();
        $payload = $this->payload(['email_consent' => true, 'consent_disclosures' => ['sms' => ['text' => 'Send texts?', 'version' => '1']]]);
        $first = $this->postJson('/api/v1/leads', $payload, $headers)->assertCreated()->json();
        Lead::sole()->update(['first_name' => 'Changed after capture']);
        $this->travel(2)->hours();
        $this->postJson('/api/v1/leads', array_reverse($payload, true), $headers + ['User-Agent' => 'changed-agent'])->assertCreated()->assertExactJson($first);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_submissions', 1);
        $this->assertDatabaseCount('lead_consents', 2);
        $this->assertDatabaseCount('canonical_events', 1);
        Event::assertDispatchedTimes(LeadCreated::class, 1);
        $stored = DB::table('lead_submissions')->sole();
        $this->assertStringNotContainsString('casey@example.test', $stored->response);
        $this->assertStringNotContainsString($first['data']['uuid'], $stored->response);
        $this->assertStringNotContainsString($headers['X-Lead-Submission-Secret'], json_encode($stored));
    }

    public function test_public_key_and_known_payload_cannot_recover_lead_without_independent_secret(): void
    {
        $headers = $this->headers();
        $first = $this->postJson('/api/v1/leads', $this->payload(), $headers)->assertCreated()->json();
        $wrong = array_replace($headers, ['X-Lead-Submission-Secret' => bin2hex(random_bytes(32))]);
        $this->postJson('/api/v1/leads', $this->payload(), $wrong)->assertConflict()->assertDontSee($first['data']['uuid']);
        $this->postJson('/api/v1/leads', $this->payload(), ['Idempotency-Key' => $headers['Idempotency-Key']])->assertUnprocessable()->assertDontSee($first['data']['uuid']);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_changed_contact_consent_source_cart_or_quiz_conflicts_without_mutation(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/v1/leads', $this->payload(), $headers)->assertCreated();
        foreach ([['email' => 'other@example.test'], ['sms_consent' => true], ['utm_source' => 'different'], ['referral_code' => 'other'], ['quiz_answers' => ['extra' => 'changed']]] as $change) {
            $this->postJson('/api/v1/leads', $this->payload($change), $headers)->assertConflict();
        }
        $this->postJson('/api/v1/leads', $this->payload(), $headers + ['X-Cart-Token' => (string) Str::ulid()])->assertConflict();
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_consents', 0);
        $this->assertDatabaseCount('canonical_events', 1);
    }

    public function test_retry_survives_quiz_definition_changes_without_revalidation_or_workflow_replay(): void
    {
        $quiz = Quiz::create(['name' => 'Test', 'slug' => 'test', 'is_active' => true]);
        $step = $quiz->steps()->create(['slug' => 'start', 'name' => 'Start', 'position' => 1, 'is_active' => true]);
        $question = $step->questions()->create(['slug' => 'goal', 'kind' => QuizQuestionKind::Text, 'prompt' => 'Goal', 'position' => 1, 'is_active' => true]);
        $headers = $this->headers();
        $payload = $this->payload(['quiz_slug' => 'test', 'quiz_answers' => ['goal' => 'Energy']]);
        $first = $this->postJson('/api/v1/leads', $payload, $headers)->assertCreated()->json();
        $question->update(['is_active' => false]);
        $quiz->update(['is_active' => false]);
        $this->postJson('/api/v1/leads', $payload, $headers)->assertCreated()->assertExactJson($first);
        $this->postJson('/api/v1/leads', $this->payload(['quiz_slug' => 'test', 'quiz_answers' => ['goal' => 'Changed']]), $headers)->assertConflict();
        $this->assertDatabaseCount('canonical_events', 2);
        Event::assertDispatchedTimes(LeadCreated::class, 1);
        Event::assertDispatchedTimes(QuizCompleted::class, 1);
    }

    public function test_atomic_failure_rolls_back_reservation_lead_consent_and_event(): void
    {
        $headers = $this->headers();
        $this->mock(RecordCanonicalEventAction::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('Synthetic failure')));
        $this->postJson('/api/v1/leads', $this->payload(['email_consent' => true]), $headers)->assertStatus(500);
        foreach (['leads', 'lead_submissions', 'lead_consents', 'canonical_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Event::assertNotDispatched(LeadCreated::class);
        $this->forgetMock(RecordCanonicalEventAction::class);
        $this->postJson('/api/v1/leads', $this->payload(['email_consent' => true]), $headers)->assertCreated();
        Event::assertDispatchedTimes(LeadCreated::class, 1);
    }

    public function test_referral_credit_is_preserved_and_no_key_callers_still_create_separate_leads(): void
    {
        $visitor = (string) Str::uuid();
        app(RecordReferralClickAction::class)->execute('synthetic-referral', $visitor, ['utm_source' => 'referral-source']);
        $payload = $this->payload(['referral_code' => 'synthetic-referral', 'referral_visitor_id' => $visitor, 'utm_source' => 'form-source']);
        $headers = $this->headers();
        $this->postJson('/api/v1/leads', $payload, $headers)->assertCreated();
        $this->postJson('/api/v1/leads', $payload, $headers)->assertCreated();
        $this->assertSame('referral-source', Lead::sole()->utm_source);
        $this->postJson('/api/v1/leads', $payload)->assertCreated();
        $this->postJson('/api/v1/leads', $payload)->assertCreated();
        $this->assertDatabaseCount('leads', 3);
        $this->assertDatabaseCount('lead_submissions', 1);
        Event::assertDispatchedTimes(LeadCreated::class, 3);
    }

    public function test_competing_insert_path_replays_winner_and_refuses_different_owner(): void
    {
        $headers = $this->headers();
        $first = $this->postJson('/api/v1/leads', $this->payload(), $headers)->assertCreated()->json('data');
        $data = new LeadData(first_name: 'Casey', last_name: 'Example', email: 'casey@example.test');
        $submission = LeadSubmissionData::forRequest($headers['Idempotency-Key'], $headers['X-Lead-Submission-Secret'], $this->payload(), null);
        $result = app(SubmitLeadAction::class)->execute($data, $submission);
        $this->assertSame($first, $result->response);
        $this->assertDatabaseCount('leads', 1);
        Event::assertDispatchedTimes(LeadCreated::class, 1);
        $submission->ownerHash = hash('sha256', 'other-owner');
        $this->expectException(ConflictHttpException::class);
        app(SubmitLeadAction::class)->execute($data, $submission);
    }

    public function test_deleted_lead_cannot_be_recovered_or_recreated_with_old_submission_key(): void
    {
        $headers = $this->headers();
        $first = $this->postJson('/api/v1/leads', $this->payload(), $headers)->assertCreated()->json();
        Lead::sole()->delete();
        $this->postJson('/api/v1/leads', $this->payload(), $headers)->assertConflict()->assertDontSee($first['data']['uuid']);
        $this->assertDatabaseCount('lead_submissions', 1);
        $this->assertDatabaseCount('leads', 1);
    }
}
