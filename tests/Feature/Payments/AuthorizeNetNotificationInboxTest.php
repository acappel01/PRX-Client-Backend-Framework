<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\ConfigureAuthorizeNetReceiverAction;
use App\Actions\Payments\ReceiveAuthorizeNetNotificationAction;
use App\Data\Payments\AuthorizeNetReceiverData;
use App\Data\Payments\GatewayNotificationData;
use App\Enums\Payments\GatewayEnvironment;
use App\Models\Payments\AuthorizeNetReceiver;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\GatewayNotificationConflict;
use App\Models\Payments\GatewayNotificationInbox;
use App\Models\Payments\MerchantAccount;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentLedgerScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AuthorizeNetNotificationInboxTest extends TestCase
{
    // Public historical vector, not account credentials or current live-provider qualification.
    // https://community.developer.cybersource.com/t5/Integration-and-Testing/HMAC-SHA-512-hash-not-matching-in-webhook-callback/m-p/57201
    private const KEY = '2679A5D5785C9B26CF921E1754765CDDB265C9C88400786923E5343B315954FAFB4B7BF608818F6A21766741E8637DC206F286C725908C777ED4127D8562163D';

    private const BODY = '{"notificationId":"f813b98f-3552-4eeb-ba40-acb4704eeba3","eventType":"net.authorize.payment.authcapture.created","eventDate":"2017-03-08T17:35:37.155999Z","webhookId":"63d6fea2-aa13-4b1d-a204-f5fbc15942b7","payload":{"responseCode":1,"authCode":"8VTPDC","avsResponse":"Y","authAmount":10.00,"entityName":"transaction","id":"60019392075"}}';

    private const SIGNATURE = 'sha512=9607C69D2734514554CE13D923D19939563F2FD6567D239F49F0DF0D08865632A0FB98061B4620CC52C47BB8BF6E2B69A15A67E4C98E81D7D65111076C515C6A';

    private const WEBHOOK = '63d6fea2-aa13-4b1d-a204-f5fbc15942b7';

    protected function setUp(): void
    {
        parent::setUp();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
        $this->mock(AuthorizeNetReportingClient::class, function ($mock): void {
            $mock->shouldNotReceive('merchant');
            $mock->shouldNotReceive('transaction');
        });
        $this->mock(PaymentGatewayManager::class, function ($mock): void {
            foreach (['forAccount', 'forAccountId', 'driver', 'default'] as $method) {
                $mock->shouldNotReceive($method);
            }
        });
    }

    private function fixture(string $account = '12345', GatewayEnvironment $environment = GatewayEnvironment::Sandbox): array
    {
        $merchant = MerchantAccount::factory()->create(['authnet_signature_key' => self::KEY, 'environment' => $environment]);
        $binding = GatewayAccountBinding::create([
            'merchant_account_id' => $merchant->id, 'merchant_account_uuid' => $merchant->uuid,
            'gateway_provider' => $merchant->gateway_provider->value, 'environment' => $environment->value,
            'gateway_account_id' => $account, 'currency' => 'USD',
            'canonical_account_key' => hash('sha256', implode(':', [$merchant->gateway_provider->value, $environment->value, $account])),
            'merchant_fingerprint' => app(PaymentLedgerScope::class)->merchantFingerprint($merchant),
            'provider_mapping' => null, 'verified_at' => now(),
        ]);
        $receiver = app(ConfigureAuthorizeNetReceiverAction::class)->execute(new AuthorizeNetReceiverData($binding->id, $environment, self::WEBHOOK, 'v1'));

        return [$merchant, $binding, $receiver];
    }

    private function receive(AuthorizeNetReceiver $receiver, string $body = self::BODY, ?array $headers = null, ?string $encoding = null): mixed
    {
        return app(ReceiveAuthorizeNetNotificationAction::class)->execute(new GatewayNotificationData($receiver->id, $body,
            $headers ?? ['sha512='.hash_hmac('sha512', $body, self::KEY)], $encoding));
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Invalid notification accepted.');
        } catch (ValidationException $exception) {
            $this->assertNull($exception->getPrevious());
            foreach ([self::KEY, '60019392075', '8VTPDC', 'sensitive@example.test'] as $secret) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
        }
    }

    public function test_published_text_key_vector_and_exact_retry_store_one_inactive_minimal_receipt(): void
    {
        [$merchant, $binding, $receiver] = $this->fixture();
        $first = $this->receive($receiver, headers: [self::SIGNATURE]);
        $this->assertSame('received_inactive', $first->status);
        $again = $this->receive($receiver, headers: [strtoupper(self::SIGNATURE)]);
        $this->assertSame('duplicate', $again->status);
        $this->assertSame($first->inbox_id, $again->inbox_id);
        $row = GatewayNotificationInbox::findOrFail($first->inbox_id);
        $this->assertSame('received_inactive', $row->state);
        $this->assertSame(['notification_id', 'event_type', 'event_date', 'webhook_id', 'entity_name', 'entity_id', 'merchant_reference_id'], array_keys($row->notification));
        $this->assertSame('60019392075', $row->notification['entity_id']);
        foreach (['authAmount', 'authCode', 'responseCode'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $row->notification);
        }
        foreach ([self::KEY, '60019392075', '8VTPDC', 'f813b98f'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode(DB::table('gateway_notification_inboxes')->first()));
        }
        $this->assertArrayNotHasKey('signature_key', $receiver->toArray());
        $this->assertStringNotContainsString(self::KEY, $receiver->getRawOriginal('signature_key'));
        $this->assertArrayNotHasKey('notification', $row->toArray());
        $this->assertDatabaseCount('gateway_notification_inboxes', 1);
        $this->assertDatabaseCount('gateway_notification_conflicts', 0);
        foreach (['payment_intents', 'payment_operations', 'payment_outcome_observations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_changed_body_conflicts_are_durable_idempotent_and_do_not_rewrite_receipt(): void
    {
        [, , $receiver] = $this->fixture();
        $first = $this->receive($receiver);
        $altered = str_replace('10.00', '10.0', self::BODY);
        $conflict = $this->receive($receiver, $altered);
        $again = $this->receive($receiver, $altered);
        $this->assertSame('conflict', $conflict->status);
        $this->assertSame($first->inbox_id, $conflict->inbox_id);
        $this->assertSame($conflict->conflict_id, $again->conflict_id);
        $this->assertSame('duplicate', $this->receive($receiver)->status);
        $this->assertDatabaseCount('gateway_notification_inboxes', 1);
        $this->assertDatabaseCount('gateway_notification_conflicts', 1);
        $this->assertArrayNotHasKey('notification', GatewayNotificationConflict::find($conflict->conflict_id)->toArray());
    }

    public function test_duplicate_local_receiver_rows_share_canonical_inbox_but_accounts_and_environments_do_not(): void
    {
        [, , $first] = $this->fixture();
        [, , $duplicate] = $this->fixture();
        [, , $other] = $this->fixture('54321');
        [, , $production] = $this->fixture(environment: GatewayEnvironment::Production);
        $id = $this->receive($first)->inbox_id;
        $this->assertSame($id, $this->receive($duplicate)->inbox_id);
        $this->assertNotSame($id, $this->receive($other)->inbox_id);
        $this->assertNotSame($id, $this->receive($production)->inbox_id);
        $this->assertDatabaseCount('gateway_notification_inboxes', 3);
    }

    public function test_wrong_key_encoding_case_and_changed_raw_bytes_are_rejected(): void
    {
        [, , $receiver] = $this->fixture();
        foreach ([hex2bin(self::KEY), strtolower(self::KEY), str_repeat('B', 128)] as $wrongKey) {
            $this->rejected(fn () => $this->receive($receiver, headers: ['sha512='.hash_hmac('sha512', self::BODY, $wrongKey)]));
        }
        foreach ([self::BODY."\n", str_replace('10.00', '10.0', self::BODY), json_encode(json_decode(self::BODY))] as $changed) {
            $this->rejected(fn () => $this->receive($receiver, $changed, [self::SIGNATURE]));
        }
        $this->assertDatabaseCount('gateway_notification_inboxes', 0);
    }

    public function test_header_cardinality_shape_encoding_and_body_bounds_fail_closed(): void
    {
        [, , $receiver] = $this->fixture();
        foreach ([[], [self::SIGNATURE, self::SIGNATURE], ['x' => self::SIGNATURE], [self::SIGNATURE.','.self::SIGNATURE],
            [substr(self::SIGNATURE, 7)], [' sha512='.str_repeat('a', 128)], ['sha256='.str_repeat('a', 128)], [self::SIGNATURE.'x'], [null]] as $headers) {
            $this->rejected(fn () => $this->receive($receiver, headers: $headers));
        }
        foreach (['gzip', 'identity,identity', ' identity'] as $encoding) {
            $this->rejected(fn () => $this->receive($receiver, encoding: $encoding));
        }
        $this->rejected(fn () => $this->receive($receiver, str_repeat(' ', 65537)));
        $this->assertDatabaseCount('gateway_notification_inboxes', 0);
    }

    public function test_authenticated_duplicate_keys_including_unicode_equivalents_and_ignored_fields_are_rejected(): void
    {
        [, , $receiver] = $this->fixture();
        $bodies = [
            str_replace('"notificationId":', '"notificationId":"duplicate","notificationId":', self::BODY),
            str_replace('"notificationId":', '"\\u006eotificationId":"duplicate","notificationId":', self::BODY),
            str_replace('"id":"60019392075"', '"id":"0","\\u0069d":"60019392075"', self::BODY),
            substr(self::BODY, 0, -1).',"ignored":{"sensitive":"one","sensitive":"two"}}',
            substr(self::BODY, 0, -1).',"ignored":"'.chr(255).'"}',
            '['.self::BODY.']', self::BODY.'garbage', '{"x":'.str_repeat('[', 32).'0'.str_repeat(']', 32).'}',
        ];
        foreach ($bodies as $body) {
            $this->rejected(fn () => $this->receive($receiver, $body));
        }
        $this->assertDatabaseCount('gateway_notification_inboxes', 0);
    }

    public function test_minimal_envelope_scope_and_documented_camelcase_event_are_checked(): void
    {
        [, , $receiver] = $this->fixture();
        foreach ([['webhookId', '11111111-1111-1111-1111-111111111111'], ['notificationId', 'wrong'], ['eventDate', '2026-02-30T00:00:00Z'],
            ['eventType', 'net.authorize.customer.created'], ['payload.entityName', 'customerProfile'], ['payload.id', 123]] as [$field, $value]) {
            $body = json_decode(self::BODY, true);
            data_set($body, $field, $value);
            $this->rejected(fn () => $this->receive($receiver, json_encode($body)));
        }
        $this->rejected(fn () => $this->receive($receiver, str_replace('"id":"60019392075"', '"id":123456789012345678901234567890', self::BODY)));
        $body = json_decode(self::BODY, true);
        $body['eventType'] = 'net.authorize.payment.priorAuthCapture.created';
        $body['payload']['merchantReferenceId'] = 'opaque.v1:reference-1';
        $body['payload']['email'] = 'sensitive@example.test';
        $result = $this->receive($receiver, json_encode($body));
        $projection = GatewayNotificationInbox::find($result->inbox_id)->notification;
        $this->assertSame('net.authorize.payment.priorAuthCapture.created', $projection['event_type']);
        $this->assertSame('opaque.v1:reference-1', $projection['merchant_reference_id']);
        $this->assertStringNotContainsString('sensitive@example.test', json_encode($projection));
    }

    public function test_current_credential_account_status_and_provider_drift_refuse_receipts_and_configuration(): void
    {
        [$merchant, $binding, $receiver] = $this->fixture();
        foreach ([['authnet_signature_key' => str_repeat('B', 128)], ['authnet_transaction_key' => 'changed'], ['is_active' => false],
            ['environment' => GatewayEnvironment::Production], ['provider_merchant_profile_id' => 'changed']] as $change) {
            $original = $merchant->only(array_keys($change));
            $merchant->update($change);
            $this->rejected(fn () => $this->receive($receiver));
            $this->rejected(fn () => app(ConfigureAuthorizeNetReceiverAction::class)->execute(new AuthorizeNetReceiverData($binding->id, GatewayEnvironment::Sandbox, self::WEBHOOK, 'v2')));
            $merchant->update($original);
        }
        $merchant->delete();
        $this->rejected(fn () => $this->receive($receiver));
        $this->assertDatabaseCount('gateway_notification_inboxes', 0);
    }

    public function test_storage_failure_never_returns_acceptance_or_a_partial_receipt(): void
    {
        [, , $receiver] = $this->fixture();
        foreach (['gateway_notification_inboxes', 'gateway_notification_conflicts'] as $table) {
            if ($table === 'gateway_notification_conflicts') {
                $this->receive($receiver);
            }
            $sql = DB::getDriverName() === 'mysql'
                ? "CREATE TRIGGER reject_gateway_notification BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic storage failure'"
                : "CREATE TRIGGER reject_gateway_notification BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'synthetic storage failure'); END";
            DB::statement($sql);
            $accepted = null;
            try {
                $body = $table === 'gateway_notification_conflicts' ? self::BODY."\n" : self::BODY;
                $accepted = $this->receive($receiver, $body);
                $this->fail('Storage failure returned acceptance.');
            } catch (QueryException $exception) {
                $this->assertNull($accepted);
                $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
                $this->assertStringNotContainsString(self::BODY, $exception->getMessage());
            } finally {
                DB::statement('DROP TRIGGER reject_gateway_notification');
            }
            $this->assertDatabaseCount('gateway_notification_inboxes', $table === 'gateway_notification_conflicts' ? 1 : 0);
            $this->assertDatabaseCount('gateway_notification_conflicts', 0);
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_receiver_configuration_and_notification_evidence_are_immutable_and_transactional(): void
    {
        [, $binding, $receiver] = $this->fixture();
        $repeat = app(ConfigureAuthorizeNetReceiverAction::class)->execute(new AuthorizeNetReceiverData($binding->id, GatewayEnvironment::Sandbox, strtoupper(self::WEBHOOK), 'v1'));
        $this->assertSame($receiver->id, $repeat->id);
        $first = $this->receive($receiver);
        $conflict = $this->receive($receiver, self::BODY."\n");
        foreach ([$receiver, GatewayNotificationInbox::find($first->inbox_id), GatewayNotificationConflict::find($conflict->conflict_id)] as $record) {
            foreach ([fn () => $record->fresh()->forceFill(['id' => 987])->save(), fn () => $record->fresh()->delete()] as $mutate) {
                try {
                    $mutate();
                    $this->fail('Immutable record changed.');
                } catch (LogicException) {
                    $this->assertTrue(true);
                }
            }
        }
        DB::beginTransaction();
        $newBody = str_replace('f813b98f', 'f813b98e', self::BODY);
        $this->rejected(fn () => $this->receive($receiver, $newBody));
        DB::rollBack();
        $this->assertDatabaseCount('gateway_notification_inboxes', 1);
    }
}
