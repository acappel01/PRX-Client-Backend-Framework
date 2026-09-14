<?php

namespace Tests\Feature\Attribution;

use App\Actions\Attribution\PreviewCanonicalDeliveryAction;
use App\Actions\Attribution\ReadEmailSuppressionAction;
use App\Actions\Attribution\RecordCanonicalEventAction;
use App\Models\Integrations\IntegrationIdentity;
use App\Models\Integrations\IntegrationInstance;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Services\Attribution\CanonicalEventRegistry;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailSuppressionTest extends TestCase
{
    use DatabaseMigrations;

    /** Isolated fresh schemas without transactions or unrelated historical down migrations. */
    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $lead = Lead::factory()->create(['email' => 'synthetic@example.test']);
        LeadConsent::create(['lead_id' => $lead->id, 'channel' => 'email', 'granted' => true, 'source' => 'test', 'consented_at' => now()]);
        $instance = IntegrationInstance::create(['name' => fake()->uuid(), 'provider' => 'klaviyo', 'is_active' => true, 'capabilities' => ['crm'],
            'credentials' => ['private_key' => 'synthetic-private-key'], 'settings' => [
                'suppression_read' => ['version' => 1, 'account_id' => 'ACCOUNT1', 'environment' => 'testing'],
                'canonical_event_preview' => ['version' => 1, 'environment' => 'testing', 'events' => ['lead.captured'], 'goal_keys' => []],
            ]]);
        IntegrationIdentity::record($instance, $lead, 'PROFILE1');
        $event = app(RecordCanonicalEventAction::class)->execute(name: 'lead.captured', source: 'lead.capture', dedupeKey: fake()->uuid(), occurredAt: now(),
            payload: ['source' => array_fill_keys(CanonicalEventRegistry::SOURCE_FIELDS, null), 'goal_keys' => [], 'quiz' => null], lead: $lead, environment: 'testing');

        return [$lead, $instance, $event];
    }

    private function profile(array $changes = []): array
    {
        return ['data' => ['type' => 'profile', 'id' => 'PROFILE1', 'attributes' => ['email' => 'synthetic@example.test', 'subscriptions' => ['email' => ['marketing' => array_replace([
            'can_receive_email_marketing' => true, 'consent' => 'SUBSCRIBED', 'suppression' => [], 'list_suppressions' => [],
        ], $changes)]]]]];
    }

    private function fakeReads(?array $profile = null, ?callable $during = null): void
    {
        $this->resetHttp();
        Http::fake(function ($request) use ($profile, $during) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(['2026-07-15'], $request->header('revision'));
            $this->assertSame(['Klaviyo-API-Key synthetic-private-key'], $request->header('Authorization'));
            if (str_contains($request->url(), '/accounts/')) {
                return Http::response(['data' => [['type' => 'account', 'id' => 'ACCOUNT1']]]);
            }
            $this->assertStringContainsString('/profiles/PROFILE1/', $request->url());
            $this->assertSame('subscriptions', $request['additional-fields[profile]']);
            $this->assertSame('email,subscriptions', $request['fields[profile]']);
            $during?->__invoke();

            return Http::response($profile ?? $this->profile());
        });
    }

    public function test_verified_read_only_qualifies_passive_preview_and_is_encrypted(): void
    {
        [$lead, $instance, $event] = $this->fixture();
        $this->fakeReads();
        $read = app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        $this->assertSame('clear', $read->status);
        Http::assertSentCount(2);
        $preview = app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id);
        $this->assertSame(['delivery_disabled'], $preview->reasons);
        $this->assertTrue($preview->policy_evidence['policy_eligible']);
        $this->assertSame('blocked', $preview->status);
        $this->assertSame($read->id, $preview->policy_evidence['suppression_observation_id']);
        foreach (['synthetic@example.test', 'synthetic-private-key', 'PROFILE1', 'ACCOUNT1', 'binding'] as $secret) {
            $this->assertStringNotContainsString($secret, $read->getRawOriginal('evidence'));
        }
        $this->assertArrayNotHasKey('evidence', $read->toArray());
        Http::assertSentCount(2);
    }

    public function test_missing_ambiguous_suppressed_and_non_subscribed_fields_fail_closed(): void
    {
        [$lead, $instance] = $this->fixture();
        foreach ([['suppression' => (object) []], ['list_suppressions' => (object) []], ['suppression' => null], ['list_suppressions' => null], ['can_receive_email_marketing' => 'true'], ['consent' => null],
            ['suppression' => [['reason' => 'HARD_BOUNCE']]], ['list_suppressions' => [['list_id' => 'LIST1']]],
            ['consent' => 'NEVER_SUBSCRIBED'], ['consent' => 'UNSUBSCRIBED'], ['can_receive_email_marketing' => false]] as $changes) {
            $this->fakeReads($this->profile($changes));
            $read = app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
            $this->assertNotSame('clear', $read->status);
        }
        foreach (['id', 'email'] as $field) {
            $profile = $this->profile();
            data_set($profile, $field === 'id' ? 'data.id' : 'data.attributes.email', 'OTHER');
            $this->fakeReads($profile);
            $this->assertSame('profile_identity_mismatch', app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id)->reason);
        }
    }

    public function test_failed_new_read_never_falls_back_to_old_clear_and_stale_or_local_changes_invalidate(): void
    {
        [$lead, $instance, $event] = $this->fixture();
        $this->fakeReads();
        app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        $this->travel(301)->seconds();
        $this->assertContains('suppression_unknown', app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id)->reasons);
        $this->travelBack();
        foreach ([['credentials' => ['private_key' => 'new-key']], ['provider' => 'unknown'], ['settings' => []]] as $change) {
            $saved = $instance->only(array_keys($change));
            $instance->update($change);
            $this->assertContains('suppression_unknown', app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id)->reasons);
            $instance->update($saved);
        }
        $lead->update(['email' => 'changed@example.test']);
        $this->assertContains('suppression_unknown', app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id)->reasons);
        $lead->update(['email' => 'synthetic@example.test']);
        $this->resetHttp();
        Http::fake(['*' => Http::response('secret remote text', 403)]);
        app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        $this->assertContains('suppression_unknown', app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id)->reasons);
    }

    public function test_changes_during_read_and_later_withdrawal_cannot_grant_permission(): void
    {
        [$lead, $instance, $event] = $this->fixture();
        $this->fakeReads(during: fn () => $lead->update(['email' => 'changed@example.test']));
        $this->assertSame('unknown', app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id)->status);
        $lead->update(['email' => 'synthetic@example.test']);
        $this->fakeReads();
        app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        LeadConsent::create(['lead_id' => $lead->id, 'channel' => 'email', 'granted' => false, 'source' => 'test', 'consented_at' => now()]);
        $this->assertContains('email_consent_missing', app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id)->reasons);
        $this->resetHttp();
        Http::fake();
        app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        Http::assertNothingSent();
    }

    public function test_account_shape_transport_compression_and_redirects_fail_closed(): void
    {
        [$lead, $instance] = $this->fixture();
        foreach ([Http::response(['data' => []]), Http::response(['data' => [['type' => 'account', 'id' => 'WRONG']]]),
            Http::response(['data' => [['type' => 'account', 'id' => 'ACCOUNT1'], ['type' => 'account', 'id' => 'ACCOUNT1']]]),
            Http::response('', 302, ['Location' => 'https://unexpected.test/']), Http::response(str_repeat('x', 65537)),
            Http::response('{}', 200, ['Content-Encoding' => 'gzip']), Http::failedConnection()] as $response) {
            $this->resetHttp();
            Http::fake(['*' => $response]);
            $this->assertSame('unknown', app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id)->status);
            Http::assertSentCount(1);
        }
    }

    public function test_equal_start_times_reversed_completion_and_pending_reads_never_restore_old_clear(): void
    {
        $this->freezeTime();
        [$lead, $instance, $event] = $this->fixture();
        $this->fakeReads();
        app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        $nested = false;
        $this->resetHttp();
        Http::fake(function ($request) use ($lead, $instance, $event, &$nested) {
            if (str_contains($request->url(), '/accounts/')) {
                // Reservation is visible before HTTP, even while an earlier clear result is fresh.
                $preview = app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id);
                $this->assertContains('suppression_unknown', $preview->reasons);

                return Http::response(['data' => [['type' => 'account', 'id' => 'ACCOUNT1']]]);
            }
            if (! $nested) {
                $nested = true;
                $newer = app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
                $this->assertSame('suppressed', $newer->status);

                return Http::response($this->profile());
            }

            return Http::response($this->profile(['consent' => 'UNSUBSCRIBED']));
        });
        $older = app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        $this->assertSame('clear', $older->status);
        $preview = app(PreviewCanonicalDeliveryAction::class)->execute($event->id, $instance->id);
        $this->assertContains('remote_marketing_blocked', $preview->reasons);
        $this->assertFalse($preview->policy_evidence['policy_eligible']);
    }

    public function test_outer_transaction_is_rejected_before_reservation_or_http(): void
    {
        [$lead, $instance] = $this->fixture();
        $this->resetHttp();
        Http::fake();
        DB::beginTransaction();
        try {
            app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
            $this->fail('Outer transaction was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('suppression', $exception->errors());
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('email_suppression_observations', 0);
        Http::assertNothingSent();
    }

    public function test_empty_non_eof_stream_is_closed_with_bounded_read_options_and_no_retry(): void
    {
        [$lead, $instance] = $this->fixture();
        $closed = false;
        $reads = 0;
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'eof' => fn () => false,
            'read' => function ($length) use (&$reads): string {
                $this->assertLessThanOrEqual(8192, $length);
                $reads++;

                return '';
            },
            'close' => function () use (&$closed): void {
                $closed = true;
            },
        ]);
        $this->resetHttp();
        Http::fake(function ($request, $options) use ($stream) {
            $this->assertTrue($options['stream']);
            $this->assertFalse($options['decode_content']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['read_timeout']);
            $this->assertSame(15, $options['timeout']);

            return Http::response($stream);
        });
        $read = app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        $this->assertSame('unknown', $read->status);
        $this->assertSame(1, $reads);
        $this->assertTrue($closed);
        Http::assertSentCount(1);
    }

    public function test_missing_scope_identity_and_inactive_destination_never_make_http_calls(): void
    {
        [$lead, $instance] = $this->fixture();
        $this->resetHttp();
        Http::fake();
        $instance->update(['is_active' => false]);
        $this->assertSame('unknown', app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id)->status);
        $instance->update(['is_active' => true, 'settings' => []]);
        app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        IntegrationIdentity::query()->delete();
        app(ReadEmailSuppressionAction::class)->execute($lead->id, $instance->id);
        Http::assertNothingSent();
    }
}
