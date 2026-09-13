<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Models\Patient;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The message thread: polling with the provider's `after` cursor, and opening a
 * conversation for a visit. Fixtures copied from the provider's handler
 * (PatientSelfServiceController::conversationMessages / openEncounterConversation
 * at prx-demo develop).
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private const CONVERSATION = '9f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f';

    private const MESSAGE = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    private const ENCOUNTER = '01a07dfe-6307-727b-8ef4-acddfeebcafa';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('prescribe-rx.stub', false);
        $settings = app(IntegrationSettings::class);
        $settings->prescribe_rx_enabled = true;
        $settings->prescribe_rx_api_token = 'test-org-token';
        $settings->save();

        Sanctum::actingAs(Patient::factory()->withPrxChart()->create(), ['*']);
    }

    private function message(array $overrides = []): array
    {
        return array_merge([
            'id' => self::MESSAGE,
            'content' => 'How are you feeling this week?',
            'sender_id' => 42,
            'sender_name' => 'Dr. Example',
            'is_mine' => false,
            'message_type' => 'text',
            'reply_to_message_id' => null,
            'created_at' => '2026-09-13T12:00:00+00:00',
        ], $overrides);
    }

    private function fake(array $messagesResponse, int $status = 200): void
    {
        Http::fake([
            '*/patients/*/issue-token' => Http::response(['data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()]], 201),
            '*/me/patient/conversations/*' => Http::response($messagesResponse, $status),
            '*/me/patient/encounters/*/conversation' => Http::response(['success' => true, 'data' => ['conversation_id' => self::CONVERSATION, 'encounter_id' => self::ENCOUNTER, 'subject' => 'Your visit', 'extra' => 'x']], 201),
        ]);
    }

    private function upstreamMessagesRequest(): HttpRequest
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $request) => str_contains($request->url(), '/messages'))
            ->sole();
    }

    public function test_a_poll_forwards_only_the_cursor_and_page_size_and_keeps_the_fields_the_thread_needs(): void
    {
        $this->fake(['success' => true, 'data' => [
            'messages' => [$this->message()],
            'count' => 1,
            'latest_cursor' => self::MESSAGE,
            'has_more' => false,
        ]]);

        $response = $this->getJson('/api/v1/patient/conversations/'.self::CONVERSATION.'/messages?after='.self::MESSAGE.'&per_page=50&foo=1')
            ->assertOk()
            ->assertJsonPath('data.latest_cursor', self::MESSAGE)
            ->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.messages.0.is_mine', false)
            ->assertJsonPath('data.messages.0.sender_name', 'Dr. Example');

        $this->assertArrayNotHasKey('sender_id', $response->json('data.messages.0'));

        parse_str((string) parse_url($this->upstreamMessagesRequest()->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['after' => self::MESSAGE, 'per_page' => '50'], $query);
    }

    /** Without it a poller would overwrite a good cursor with nothing. */
    public function test_an_empty_poll_keeps_a_null_cursor(): void
    {
        $this->fake(['success' => true, 'data' => ['messages' => [], 'count' => 0, 'latest_cursor' => null, 'has_more' => false]]);

        $response = $this->getJson('/api/v1/patient/conversations/'.self::CONVERSATION.'/messages?after='.self::MESSAGE)->assertOk();

        $this->assertArrayHasKey('latest_cursor', $response->json('data'));
        $this->assertNull($response->json('data.latest_cursor'));
        $this->assertSame([], $response->json('data.messages'));
    }

    public function test_the_first_page_is_still_a_list(): void
    {
        $this->fake(['success' => true, 'data' => [$this->message(), $this->message(['id' => 'b', 'is_mine' => true])]]);

        $this->getJson('/api/v1/patient/conversations/'.self::CONVERSATION.'/messages')
            ->assertOk()
            ->assertJsonPath('data.1.is_mine', true)
            ->assertJsonCount(2, 'data');

        parse_str((string) parse_url($this->upstreamMessagesRequest()->url(), PHP_URL_QUERY), $query);
        $this->assertSame([], $query);
    }

    /** Assert NOTHING was sent: a faked upstream 422 would otherwise make this pass on its own. */
    public function test_a_malformed_cursor_is_refused_before_the_provider(): void
    {
        $this->fake(['success' => false, 'message' => 'bad cursor'], 422);

        $this->getJson('/api/v1/patient/conversations/'.self::CONVERSATION.'/messages?after=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('after');
        $this->getJson('/api/v1/patient/conversations/'.self::CONVERSATION.'/messages?per_page=500')
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/patient/conversations/'.self::CONVERSATION.'/messages?after[]=x')
            ->assertJsonValidationErrors('after');

        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/messages'));
    }

    public function test_a_non_uuid_conversation_id_never_reaches_the_provider(): void
    {
        $this->fake([]);

        $this->getJson('/api/v1/patient/conversations/../../patients/x/messages')->assertNotFound();
        $this->getJson('/api/v1/patient/conversations/not-a-uuid/messages')->assertNotFound();

        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/messages'));
    }

    public function test_opening_a_conversation_for_a_visit_returns_only_its_ids(): void
    {
        $this->fake([]);

        $response = $this->postJson('/api/v1/patient/encounters/'.self::ENCOUNTER.'/conversation')
            ->assertCreated()
            ->assertJsonPath('data.conversation_id', self::CONVERSATION)
            ->assertJsonPath('data.encounter_id', self::ENCOUNTER);

        $this->assertArrayNotHasKey('extra', $response->json('data'));
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_contains($r->url(), '/conversation'))->sole();
        $this->assertSame('POST', $sent->method());
        $this->assertSame(['Bearer patient-token'], $sent->header('Authorization'));
    }
}
