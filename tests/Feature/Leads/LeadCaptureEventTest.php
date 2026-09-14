<?php

namespace Tests\Feature\Leads;

use App\Actions\Attribution\RecordCanonicalEventAction;
use App\Actions\Referral\RecordReferralClickAction;
use App\Enums\Quiz\QuizQuestionKind;
use App\Events\Leads\LeadCreated;
use App\Events\Quiz\QuizCompleted;
use App\Models\Attribution\CanonicalEvent;
use App\Models\Kb\HealthGoal;
use App\Models\Lead;
use App\Models\Quiz\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LeadCaptureEventTest extends TestCase
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

    public function test_lead_capture_records_one_encrypted_internal_event_without_contact_or_raw_response(): void
    {
        $this->postJson('/api/v1/leads', $this->payload(['utm_source' => 'campaign-source', 'email_consent' => true]))->assertCreated();
        $event = CanonicalEvent::sole();
        $this->assertSame('lead.captured', $event->name);
        $this->assertSame(Lead::sole()->id, $event->lead_id);
        $this->assertSame('campaign-source', $event->payload['source']['utm_source']);
        $this->assertSame([], $event->payload['goal_keys']);
        $this->assertNull($event->payload['quiz']);
        $this->assertArrayNotHasKey('email', $event->payload);
        $this->assertArrayNotHasKey('payload', $event->toArray());
        $this->assertStringNotContainsString('campaign-source', $event->getRawOriginal('payload'));
        $this->assertDatabaseCount('lead_consents', 1);
        Event::assertDispatchedTimes(LeadCreated::class, 1);
        Event::assertNotDispatched(QuizCompleted::class);
    }

    public function test_quiz_capture_projects_general_goals_but_not_conditions_medications_or_allergies(): void
    {
        HealthGoal::create(['name' => 'More energy', 'slug' => 'more-energy', 'is_active' => true, 'show_in_quiz' => true]);
        $quiz = Quiz::create(['name' => 'General quiz', 'slug' => 'general-quiz', 'is_active' => true]);
        $step = $quiz->steps()->create(['slug' => 'start', 'name' => 'Start', 'position' => 1, 'is_active' => true]);
        $step->questions()->create(['slug' => 'goals', 'kind' => QuizQuestionKind::HealthGoals, 'prompt' => 'Goals', 'position' => 1, 'is_active' => true]);
        foreach (['conditions', 'medications', 'allergies'] as $index => $slug) {
            $step->questions()->create(['slug' => $slug, 'kind' => QuizQuestionKind::Text, 'prompt' => $slug, 'position' => $index + 2, 'is_active' => true]);
        }
        $this->postJson('/api/v1/leads', $this->payload([
            'quiz_slug' => 'general-quiz',
            'quiz_answers' => ['goals' => ['more-energy'], 'conditions' => 'excluded-condition', 'medications' => 'excluded-medication', 'allergies' => 'excluded-allergy'],
        ]))->assertCreated();
        $this->assertDatabaseCount('canonical_events', 2);
        foreach (CanonicalEvent::all() as $event) {
            $this->assertSame(['more-energy'], $event->payload['goal_keys']);
            $this->assertSame($quiz->id, $event->payload['quiz']['id']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->payload['quiz']['version']);
            $this->assertStringNotContainsString('excluded-', json_encode($event->payload));
        }
        Event::assertDispatchedTimes(QuizCompleted::class, 1);
    }

    public function test_event_failure_rolls_back_lead_and_consent_before_existing_workflows_run(): void
    {
        $this->mock(RecordCanonicalEventAction::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('Event recording failed.')));
        $this->postJson('/api/v1/leads', $this->payload(['email_consent' => true]))->assertStatus(500);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('lead_consents', 0);
        $this->assertDatabaseCount('canonical_events', 0);
        Event::assertNotDispatched(LeadCreated::class);
    }

    public function test_original_capture_source_remains_visible_after_referral_tuple_replacement(): void
    {
        $visitor = (string) Str::uuid();
        app(RecordReferralClickAction::class)->execute('example-referral', $visitor, ['utm_source' => 'referral-source']);
        $this->postJson('/api/v1/leads', $this->payload(['utm_source' => 'form-source', 'utm_medium' => 'form-medium',
            'referral_code' => 'example-referral', 'referral_visitor_id' => $visitor]))->assertCreated();
        $this->assertSame('referral-source', Lead::sole()->utm_source);
        $this->assertNull(Lead::sole()->utm_medium);
        $this->assertSame('form-source', CanonicalEvent::sole()->payload['source']['utm_source']);
        $this->assertSame('form-medium', CanonicalEvent::sole()->payload['source']['utm_medium']);
    }
}
