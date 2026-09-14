<?php

namespace Tests\Feature\Attribution;

use App\Actions\Attribution\RecordCanonicalEventAction;
use App\Enums\Privacy\DataClassification;
use App\Enums\Quiz\QuizQuestionKind;
use App\Models\Attribution\CanonicalEvent;
use App\Models\Customer;
use App\Models\Kb\HealthGoal;
use App\Models\Lead;
use App\Models\Quiz\Quiz;
use App\Services\Attribution\CanonicalEventRegistry;
use App\Services\Attribution\LeadEventPayload;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CanonicalEventTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['source' => array_fill_keys(CanonicalEventRegistry::SOURCE_FIELDS, null),
            'goal_keys' => [], 'quiz' => null];
    }

    private function record(array $overrides = []): CanonicalEvent
    {
        return app(RecordCanonicalEventAction::class)->execute(...array_replace([
            'name' => 'lead.captured', 'source' => 'lead.capture', 'dedupeKey' => 'lead:123:captured',
            'occurredAt' => CarbonImmutable::parse('2026-09-14T12:00:00.123456Z'),
            'payload' => $this->payload(), 'origin' => 'admin', 'environment' => 'testing',
        ], $overrides));
    }

    public function test_duplicate_recording_returns_the_original_event_id_and_recording_time(): void
    {
        $event = $this->record();
        $this->travel(1)->hour();
        $again = $this->record(['payload' => array_reverse($this->payload(), true)]);
        $this->assertSame($event->event_id, $again->event_id);
        $this->assertTrue($event->recorded_at->equalTo($again->recorded_at));
        $this->assertSame('2026-09-14 12:00:00.123456', $again->occurred_at->format('Y-m-d H:i:s.u'));
        $this->assertDatabaseCount('canonical_events', 1);
    }

    public function test_reused_identity_cannot_change_payload_occurrence_or_subject(): void
    {
        $event = $this->record();
        $payload = $this->payload();
        $payload['source']['utm_source'] = 'different';
        foreach ([['payload' => $payload], ['occurredAt' => now()], ['lead' => Lead::factory()->create()],
            ['customer' => Customer::factory()->create()]] as $change) {
            try {
                $this->record($change);
                $this->fail('Conflicting canonical event was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('event', $exception->errors());
            }
        }
        $this->assertDatabaseCount('canonical_events', 1);
        $this->assertSame($event->payload, $event->fresh()->payload);
    }

    public function test_event_names_share_the_same_explicit_source_identity_reservation(): void
    {
        $payload = $this->payload();
        $payload['quiz'] = ['id' => 1, 'version' => str_repeat('a', 64)];
        $this->record(['payload' => $payload]);
        $this->expectException(ValidationException::class);
        $this->record(['name' => 'quiz.completed', 'payload' => $payload]);
    }

    public function test_source_origin_environment_and_case_distinguish_event_identities(): void
    {
        $this->record();
        foreach ([['source' => 'import.capture'], ['origin' => 'other-admin'], ['environment' => 'production'],
            ['dedupeKey' => 'Lead:123:captured']] as $change) {
            $this->record($change);
        }
        $this->assertDatabaseCount('canonical_events', 5);
        $this->assertSame(5, CanonicalEvent::query()->distinct()->count('event_id'));
    }

    public function test_event_insert_rolls_back_with_the_producer_transaction(): void
    {
        DB::beginTransaction();
        $lead = Lead::factory()->create();
        $this->record(['lead' => $lead]);
        $this->assertDatabaseCount('canonical_events', 1);
        DB::rollBack();
        $this->assertDatabaseCount('canonical_events', 0);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_payload_and_identity_references_are_encrypted_or_hidden_from_generic_serialization(): void
    {
        $payload = $this->payload();
        $payload['source']['utm_source'] = 'private-campaign-example';
        $event = $this->record(['payload' => $payload, 'lead' => Lead::factory()->create()]);
        $this->assertStringNotContainsString('private-campaign-example', $event->getRawOriginal('payload'));
        $this->assertSame('private-campaign-example', $event->fresh()->payload['source']['utm_source']);
        $event->load(['lead', 'customer']);
        foreach (['payload', 'lead_id', 'customer_id', 'dedupe_key', 'lead', 'customer'] as $key) {
            $this->assertArrayNotHasKey($key, $event->toArray());
        }
        $this->assertArrayNotHasKey('delivery_status', $event->toArray());
    }

    public function test_registry_refuses_unregistered_events_and_wholesale_or_clinical_payload_fields(): void
    {
        $invalid = [];
        foreach (['conditions', 'medications', 'allergies', 'quiz_answers', 'lead_uuid', 'email', 'landing_url'] as $field) {
            $invalid[] = ['payload' => $this->payload() + [$field => 'not-allowed']];
        }
        $invalid[] = ['name' => 'payment.captured'];
        $invalid[] = ['schemaVersion' => 2];
        $payload = $this->payload();
        $payload['source']['patient_id'] = 'not-allowed';
        $invalid[] = ['payload' => $payload];
        foreach ($invalid as $change) {
            try {
                $this->record($change);
                $this->fail('Unregistered payload or event was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('canonical_events', 0);
    }

    public function test_recorded_events_refuse_ordinary_update_and_delete(): void
    {
        $event = $this->record();
        foreach ([fn () => $event->fresh()->update(['event_id' => 'different']), fn () => $event->fresh()->delete()] as $change) {
            try {
                $change();
                $this->fail('Immutable event history changed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('event', $exception->errors());
            }
        }
        $this->assertSame($event->event_id, $event->fresh()->event_id);
    }

    public function test_projection_keeps_approved_goals_and_respects_each_question_classification(): void
    {
        $quiz = Quiz::create(['name' => 'Goals', 'slug' => 'goals', 'is_active' => true]);
        $step = $quiz->steps()->create(['name' => 'Goals', 'slug' => 'goals', 'position' => 1, 'is_active' => true]);
        $question = $step->questions()->create(['slug' => 'aspirations', 'kind' => QuizQuestionKind::HealthGoals,
            'prompt' => 'Your goals?', 'is_active' => true, 'position' => 1]);
        $step->questions()->create(['slug' => 'conditions', 'kind' => QuizQuestionKind::Text,
            'prompt' => 'Conditions?', 'data_class' => DataClassification::General, 'is_active' => true, 'position' => 2]);
        HealthGoal::create(['name' => 'More energy', 'slug' => 'more-energy', 'is_active' => true, 'show_in_quiz' => true]);
        HealthGoal::create(['name' => 'Retired goal', 'slug' => 'retired-goal', 'is_active' => false, 'show_in_quiz' => true]);
        $lead = Lead::factory()->create(['quiz_id' => $quiz->id, 'quiz_completed_at' => now(), 'quiz_answers' => [
            'aspirations' => ['more-energy', 'more-energy', 'retired-goal', 'made-up'],
            'conditions' => 'Condition details', 'medications' => ['Medication details'], 'allergies' => ['Allergy details'],
        ]]);
        $projector = app(LeadEventPayload::class);
        $payload = $projector->forLead($lead);
        $this->assertSame(['more-energy'], $payload['goal_keys']);
        $this->assertSame(DataClassification::Sensitive, $question->effectiveDataClass());
        $this->assertSame($quiz->id, $payload['quiz']['id']);
        $this->assertSame(64, strlen($payload['quiz']['version']));
        $event = $this->record(['lead' => $lead, 'payload' => $payload]);
        $this->assertStringNotContainsString('details', json_encode($event->payload));

        $question->update(['data_class' => DataClassification::Phi]);
        $restricted = $projector->forLead($lead);
        $this->assertSame([], $restricted['goal_keys']);
        $this->assertNotSame($payload['quiz']['version'], $restricted['quiz']['version']);
        $this->assertSame(['more-energy'], $event->fresh()->payload['goal_keys']);
        $this->assertSame($event->event_id, $this->record(['lead' => $lead, 'payload' => $payload])->event_id);
    }

    public function test_projection_excludes_full_urls_query_strings_and_credential_bearing_lead_uuid(): void
    {
        $lead = Lead::factory()->create(['utm_source' => 'newsletter', 'utm_medium' => 'https://example.test/private?token=secret',
            'utm_campaign' => 'token=secret', 'utm_term' => '/api/v1/leads/secret']);
        $lead->utm_content = strtoupper($lead->uuid);
        $payload = app(LeadEventPayload::class)->forLead($lead);
        $this->assertSame(['utm_source' => 'newsletter', 'utm_medium' => null, 'utm_campaign' => null,
            'utm_term' => null, 'utm_content' => null], $payload['source']);
        $this->assertSame([], $payload['goal_keys']);
        $this->assertNull($payload['quiz']);
    }
}
