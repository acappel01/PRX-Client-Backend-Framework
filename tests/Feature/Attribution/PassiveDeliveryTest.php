<?php

namespace Tests\Feature\Attribution;

use App\Actions\Attribution\PreviewCanonicalDeliveryAction;
use App\Actions\Attribution\RecordCanonicalEventAction;
use App\Actions\Attribution\RecordCaptureTouchpointAction;
use App\Models\Attribution\CanonicalDelivery;
use App\Models\Attribution\CanonicalDeliveryEvaluation;
use App\Models\Attribution\CanonicalEvent;
use App\Models\Integrations\IntegrationInstance;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Services\Attribution\CanonicalEventRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PassiveDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function event(?Lead $lead = null, array $source = [], string $name = 'lead.captured'): CanonicalEvent
    {
        return app(RecordCanonicalEventAction::class)->execute(
            name: $name, source: 'lead.capture', dedupeKey: 'capture:'.fake()->uuid(), occurredAt: now(),
            payload: ['source' => array_replace(array_fill_keys(CanonicalEventRegistry::SOURCE_FIELDS, null), $source),
                'goal_keys' => ['more-energy', 'unapproved-goal'], 'quiz' => $name === 'quiz.completed' ? ['id' => 1, 'version' => str_repeat('a', 64)] : null],
            lead: $lead, environment: 'testing',
        );
    }

    private function destination(): IntegrationInstance
    {
        return IntegrationInstance::create(['name' => fake()->uuid(), 'provider' => 'klaviyo', 'is_active' => true,
            'capabilities' => ['crm'], 'settings' => ['canonical_event_preview' => [
                'version' => 1, 'environment' => 'testing', 'events' => ['lead.captured', 'quiz.completed'], 'goal_keys' => ['more-energy'],
            ]]]);
    }

    private function consent(Lead $lead, bool $granted): void
    {
        LeadConsent::create(['lead_id' => $lead->id, 'channel' => 'email', 'granted' => $granted,
            'source' => 'test', 'consented_at' => now()]);
    }

    public function test_capture_history_preserves_atomic_source_and_occurrence_on_replay(): void
    {
        $lead = Lead::factory()->create(['utm_source' => 'new-summary']);
        $event = $this->event($lead, ['utm_source' => 'original-source', 'utm_campaign' => 'launch']);
        $action = app(RecordCaptureTouchpointAction::class);
        $touch = $action->execute($event->id);
        $this->travel(1)->hour();
        $again = $action->execute($event->id);
        $this->assertSame($touch->touchpoint_id, $again->touchpoint_id);
        $this->assertTrue($touch->occurred_at->equalTo($event->occurred_at));
        $this->assertTrue($touch->recorded_at->equalTo($again->recorded_at));
        $this->assertSame('original-source', $again->source_tuple['utm_source']);
        $this->assertSame('new-summary', $lead->fresh()->utm_source);
        $this->assertStringNotContainsString('original-source', $touch->getRawOriginal('source_tuple'));
        $this->assertArrayNotHasKey('source_tuple', $touch->toArray());
        $this->assertDatabaseCount('attribution_touchpoints', 1);
    }

    public function test_capture_refuses_non_capture_events_urls_and_credential_shaped_source_values(): void
    {
        $events = [$this->event(name: 'quiz.completed')];
        foreach (['https://example.test/?secret=yes', '/private/path', fake()->uuid(), 'token=secret'] as $value) {
            $events[] = $this->event(source: ['utm_source' => $value]);
        }
        foreach ($events as $event) {
            try {
                app(RecordCaptureTouchpointAction::class)->execute($event->id);
                $this->fail('Unsupported capture accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('attribution_touchpoints', 0);
    }

    public function test_preview_has_stable_instance_scoped_identity_and_no_send_or_automatic_subscription(): void
    {
        Http::fake();
        $lead = Lead::factory()->create();
        $this->consent($lead, true);
        $event = $this->event($lead, ['utm_source' => 'private-source']);
        $instance = $this->destination();
        $action = app(PreviewCanonicalDeliveryAction::class);
        $first = $action->execute($event->id, $instance->id);
        $second = $action->execute($event->id, $instance->id);
        $other = $action->execute($event->id, $this->destination()->id);
        $this->assertSame($first->canonical_delivery_id, $second->canonical_delivery_id);
        $this->assertNotSame($first->canonical_delivery_id, $other->canonical_delivery_id);
        $this->assertSame(['suppression_unknown'], $first->reasons);
        $this->assertSame('blocked', $first->status);
        $this->assertSame(['event_id' => $event->event_id, 'name' => 'lead.captured',
            'occurred_at' => $event->occurred_at->toISOString(), 'goal_keys' => ['more-energy']], $first->projection);
        foreach ([$lead->uuid, $lead->email, 'private-source', 'unapproved-goal'] as $excluded) {
            $this->assertStringNotContainsString($excluded, json_encode($first->projection));
        }
        $this->assertArrayNotHasKey('projection', $first->toArray());
        $this->assertArrayNotHasKey('policy_evidence', $first->toArray());
        $this->assertStringNotContainsString('more-energy', $first->getRawOriginal('projection'));
        $this->assertDatabaseCount('canonical_deliveries', 2);
        $this->assertDatabaseCount('canonical_delivery_evaluations', 3);
        Http::assertNothingSent();
    }

    public function test_every_evaluation_rechecks_consent_and_configuration_without_rewriting_history(): void
    {
        $lead = Lead::factory()->create(['email_consent' => true]);
        $event = $this->event($lead);
        $instance = $this->destination();
        $action = app(PreviewCanonicalDeliveryAction::class);
        $unknown = $action->execute($event->id, $instance->id);
        $this->assertContains('email_consent_missing', $unknown->reasons);
        $this->consent($lead, true);
        $granted = $action->execute($event->id, $instance->id);
        $this->assertSame(['suppression_unknown'], $granted->reasons);
        $this->consent($lead, false);
        $instance->update(['is_active' => false, 'settings' => []]);
        $this->travel(1)->minute();
        $withdrawn = $action->execute($event->id, $instance->id);
        $this->assertContains('email_consent_missing', $withdrawn->reasons);
        $this->assertContains('destination_unavailable', $withdrawn->reasons);
        $this->assertContains('destination_policy_missing', $withdrawn->reasons);
        $this->assertSame(['suppression_unknown'], $granted->fresh()->reasons);
        $this->assertNotSame($granted->policy_evidence['configuration_fingerprint'], $withdrawn->policy_evidence['configuration_fingerprint']);
        $this->assertTrue($withdrawn->evaluated_at->greaterThan($granted->evaluated_at));
        $this->assertSame(['more-energy'], $granted->fresh()->projection['goal_keys']);
        $this->assertSame([], $withdrawn->projection['goal_keys']);
    }

    public function test_deleted_subject_unknown_provider_and_environment_mismatch_fail_closed(): void
    {
        $lead = Lead::factory()->create();
        $this->consent($lead, true);
        $event = $this->event($lead);
        $lead->delete();
        $instance = $this->destination();
        $instance->update(['provider' => 'missing', 'settings' => ['canonical_event_preview' => [
            'version' => 1, 'environment' => 'production', 'events' => ['lead.captured'],
        ]]]);
        $result = app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id);
        foreach (['subject_unavailable', 'destination_unavailable', 'destination_policy_missing', 'suppression_unknown'] as $reason) {
            $this->assertContains($reason, $result->reasons);
        }
    }

    public function test_settings_cannot_claim_suppression_clear_and_retired_event_schema_rolls_back(): void
    {
        $lead = Lead::factory()->create();
        $this->consent($lead, true);
        $event = $this->event($lead);
        $instance = $this->destination();
        $settings = $instance->settings;
        $settings['canonical_event_preview']['suppression'] = 'clear';
        $settings['canonical_event_preview']['enabled'] = true;
        $instance->update(['settings' => $settings]);
        $evaluation = app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id);
        $this->assertSame(['suppression_unknown'], $evaluation->reasons);
        $this->assertSame('blocked', $evaluation->status);
        // Simulate a future producer schema: current adapter refuses it, with no partial reservation.
        DB::table('canonical_events')->where('id', $event->id)->update(['schema_version' => 999]);
        $other = $this->destination();
        try {
            app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $other->id);
            $this->fail('Unknown event schema was projected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertDatabaseCount('canonical_deliveries', 1);
        $this->assertDatabaseCount('canonical_delivery_evaluations', 1);
    }

    public function test_all_history_rows_reject_model_mutation_and_rollback_with_the_transaction(): void
    {
        $event = $this->event();
        $touch = app(RecordCaptureTouchpointAction::class)->execute($event->id);
        $evaluation = app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $this->destination()->id);
        $delivery = CanonicalDelivery::findOrFail($evaluation->canonical_delivery_id);
        foreach ([$touch, $evaluation, $delivery] as $record) {
            foreach ([fn () => $record->fresh()->forceFill(['id' => 987])->save(), fn () => $record->fresh()->delete()] as $mutate) {
                try {
                    $mutate();
                    $this->fail('History mutation accepted.');
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        }
        DB::beginTransaction();
        app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $delivery->integration_instance_id);
        DB::rollBack();
        $this->assertSame(1, CanonicalDeliveryEvaluation::count());
    }
}
