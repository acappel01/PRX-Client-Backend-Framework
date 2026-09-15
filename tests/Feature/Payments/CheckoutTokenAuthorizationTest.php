<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\DispatchPreparedPaymentAction;
use App\Actions\Payments\IssueCheckoutTokenGrantAction;
use App\Actions\Payments\PreparePaymentDispatchAction;
use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\ReservePaymentOperationReferenceAction;
use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Contracts\Payments\AuthorizeNetInstrumentAuthorization;
use App\Data\Payments\CheckoutTokenGrantData;
use App\Data\Payments\GatewayAccountBindingData;
use App\Data\Payments\PaymentDispatchPreparationData;
use App\Data\Payments\PaymentDispatchRequest;
use App\Data\Payments\PaymentIntentData;
use App\Data\Payments\PaymentOperationData;
use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\Payments\CheckoutTokenConsumption;
use App\Models\Payments\CheckoutTokenGrant;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentTransportInvocation;
use App\Services\Payments\AuthorizeNetDispatchTransport;
use App\Services\Payments\AuthorizeNetMutationXml;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\CheckoutTokenAuthorization;
use App\Services\Payments\CheckoutTokenScope;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationReferenceScope;
use App\Settings\PortalSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutTokenAuthorizationTest extends TestCase
{
    private Patient $patient;

    private string $bearer;

    private MerchantAccount $merchant;

    private Order $order;

    private GatewayAccountBinding $binding;

    private PaymentIntent $intent;

    protected function setUp(): void
    {
        parent::setUp();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->mock(PaymentGatewayManager::class, function ($mock): void {
            $mock->shouldNotReceive('forAccount', 'forAccountId', 'driver', 'default');
        });
        Http::preventStrayRequests();
        $this->patient = Patient::factory()->create(['email_verified_at' => now()]);
        $this->bearer = $this->patient->createToken('synthetic-checkout', ['patient:*'], now()->addHour())->plainTextToken;
        $customer = Customer::factory()->create(['portal_account_id' => $this->patient->id]);
        app(PortalSettings::class)->two_factor_policy = 'off';
        config()->set('payments.checkout_token_authorization_enabled', true);
        $this->order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $this->merchant = MerchantAccount::factory()->create();
        Http::fake(fn () => Http::response('<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>123</gatewayId><currencies><currency>USD</currency></currencies></getMerchantDetailsResponse>'));
        $this->binding = app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '123', 'USD'));
        $this->intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $customer->id, $this->merchant->id, GatewayProvider::AuthorizeNet, GatewayEnvironment::Sandbox, 2500, 'USD', 'local.checkout'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function preparation(): PaymentDispatchPreparation
    {
        $op = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $this->intent->uuid, PaymentOperationPurpose::Sale, 2500, 'local.checkout'));
        $ref = app(ReservePaymentOperationReferenceAction::class)->execute($op->uuid, $this->binding->id);

        return app(PreparePaymentDispatchAction::class)->execute(new PaymentDispatchPreparationData((string) Str::uuid(), $ref->id, 'local.checkout'));
    }

    private function data(PaymentDispatchPreparation $prep, array $changes = []): CheckoutTokenGrantData
    {
        return new CheckoutTokenGrantData(...($changes + ['uuid' => (string) Str::uuid(), 'preparation_uuid' => $prep->uuid,
            'accepted_quote_fingerprint' => $this->intent->order_snapshot_fingerprint, 'accepted_amount_minor' => 2500, 'accepted_currency' => 'USD', 'acknowledgement_version' => 'checkout_payment_v1']));
    }

    private function issue(CheckoutTokenGrantData $data, string $token = 'synthetic-checkout-token'): CheckoutTokenGrant
    {
        return app(IssueCheckoutTokenGrantAction::class)->execute($data, $this->bearer, $token);
    }

    private function resolver(CheckoutTokenGrant $grant, string $token = 'synthetic-checkout-token'): CheckoutTokenAuthorization
    {
        return new CheckoutTokenAuthorization($grant->uuid, $this->bearer, $token, app(CheckoutTokenScope::class), app(PaymentLedgerScope::class));
    }

    private function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected refusal');
        } catch (ValidationException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_real_customer_quote_grant_consumes_durably_before_concrete_wire_and_never_replays(): void
    {
        $prep = $this->preparation();
        $data = $this->data($prep);
        $grant = $this->issue($data);
        $this->assertSame($grant->id, $this->issue($data)->id);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('checkout_payment_v1', $grant->scope['acknowledgement_version']);
        $this->assertSame($this->intent->customer_uuid, $grant->scope['customer_uuid']);
        $this->assertFalse(app()->bound(AuthorizeNetInstrumentAuthorization::class));
        config()->set('payments.dispatch_enabled', true);
        config()->set('payments.authorize_net_transport_enabled', true);
        $http = new Factory;
        $http->preventStrayRequests();
        $calls = 0;
        $http->fake(function ($wire) use ($http, &$calls) {
            $calls++;
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('checkout_token_consumptions', 1);
            $doc = new \DOMDocument;
            @$doc->loadXML($wire->body());
            $this->assertSame('synthetic-checkout-token', $doc->getElementsByTagName('dataValue')->item(0)->textContent);
            $ref = $doc->getElementsByTagName('refId')->item(0)->textContent;

            return $http->response('<createTransactionResponse xmlns="'.AuthorizeNetMutationXml::NS.'"><refId>'.$ref.'</refId><messages><resultCode>Ok</resultCode></messages><transactionResponse><responseCode>1</responseCode><transId>9001</transId></transactionResponse></createTransactionResponse>');
        });
        $resolver = $this->resolver($grant);
        $transport = new AuthorizeNetDispatchTransport(app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class), $resolver, $http);
        $action = new DispatchPreparedPaymentAction($transport, app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class));
        $attempt = $action->execute($prep->uuid, 'local.checkout');
        $this->assertSame('response_observed', $attempt->status);
        $this->assertSame($attempt->id, $action->execute($prep->uuid, 'local.checkout')->id);
        $this->assertSame(1, $calls);
        $this->assertSame($grant->id, $this->issue($data)->id);
        $raw = json_encode(DB::table('checkout_token_grants')->first());
        $this->assertStringNotContainsString('synthetic-checkout-token', $raw);
        $this->assertStringNotContainsString($this->bearer, $raw);
        $this->assertStringNotContainsString($this->patient->uuid, $raw);
        $this->assertArrayNotHasKey('token_fingerprint', $grant->toArray());
        try {
            serialize($resolver);
            $this->fail();
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        ob_start();
        var_dump($resolver);
        $debug = ob_get_clean();
        $this->assertStringNotContainsString('synthetic-checkout-token', $debug);
    }

    #[DataProvider('refusalCases')]
    public function test_issue_rejects_unowned_expired_unaccepted_or_changed_scope(string $case): void
    {
        $prep = $this->preparation();
        $changes = [];
        switch ($case) {
            case 'wrong-quote':$changes['accepted_quote_fingerprint'] = str_repeat('a', 64);
                break;
            case 'wrong-amount':$changes['accepted_amount_minor'] = 2499;
                break;
            case 'wrong-currency':$changes['accepted_currency'] = 'CAD';
                break;
            case 'missing-ack':$changes['acknowledgement_version'] = '';
                break;
            case 'foreign-patient':$other = Patient::factory()->create(['email_verified_at' => now()]);
                $this->bearer = $other->createToken('foreign', ['patient:*'])->plainTextToken;
                break;
            case 'wrong-ability':$this->bearer = $this->patient->createToken('wrong', ['operator:*'])->plainTextToken;
                break;
            case 'revoked':$this->patient->tokens()->delete();
                break;
            case 'expired':$this->patient->tokens()->update(['expires_at' => now()->subSecond()]);
                break;
            case 'global-expiry':config()->set('sanctum.expiration', 1);
                $this->patient->tokens()->update(['created_at' => now()->subMinutes(2)]);
                break;
            case 'deleted-patient':$this->patient->delete();
                break;
            case 'required-two-factor':app(PortalSettings::class)->two_factor_policy = 'required';
                break;
            case 'order-drift':$this->order->update(['total_amount' => '26.00']);
                break;
            case 'credential-drift':$this->merchant->update(['authnet_transaction_key' => 'changed']);
                break;
            case 'guest-email':$this->bearer = $this->patient->email;
                break;
            case 'disabled':config()->set('payments.checkout_token_authorization_enabled', false);
                break;
        }
        $this->invalid(fn () => $this->issue($this->data($prep, $changes)));
        $this->assertDatabaseCount('checkout_token_grants', 0);
        Http::assertNothingSent();
    }

    public static function refusalCases(): array
    {
        return array_map(fn ($case) => [$case], ['wrong-quote', 'wrong-amount', 'wrong-currency', 'missing-ack', 'foreign-patient', 'wrong-ability', 'revoked', 'expired', 'global-expiry', 'deleted-patient', 'required-two-factor', 'order-drift', 'credential-drift', 'guest-email', 'disabled']);
    }

    public function test_zero_global_expiration_matches_sanctum_disabled_expiration_semantics(): void
    {
        config()->set('sanctum.expiration', 0);
        $grant = $this->issue($this->data($this->preparation()));
        $this->assertTrue($grant->exists);
    }

    #[DataProvider('sessionLifetimeCases')]
    public function test_current_portal_idle_and_absolute_limits_apply_at_issue_and_consume(string $phase, string $policy): void
    {
        $settings = app(PortalSettings::class);
        $settings->session_idle_minutes = 30;
        $settings->session_max_hours = 12;
        $this->patient->tokens()->update(['created_at' => now()->subHours(2), 'last_used_at' => now()->subMinutes(2), 'expires_at' => now()->addHour()]);
        $before = $this->patient->tokens()->sole()->getAttributes();
        $prep = $this->preparation();
        $data = $this->data($prep);
        if ($phase === 'consume') {
            $grant = $this->issue($data);
            $request = $this->claim($prep);
        }
        if ($policy === 'idle') {
            $settings->session_idle_minutes = 1;
        } else {
            $settings->session_max_hours = 1;
        }
        if ($phase === 'issue') {
            $this->invalid(fn () => $this->issue($data));
            $this->assertDatabaseCount('checkout_token_grants', 0);
        } else {
            $this->invalid(fn () => $this->resolver($grant)->authorize($request, $this->intent->customer_uuid));
        }
        $this->assertDatabaseCount('checkout_token_consumptions', 0);
        $this->assertSame($before, $this->patient->tokens()->sole()->getAttributes());
        Http::assertNothingSent();
    }

    public static function sessionLifetimeCases(): array
    {
        return [['issue', 'idle'], ['issue', 'max'], ['consume', 'idle'], ['consume', 'max']];
    }

    public function test_global_token_and_preparation_uniqueness_refuse_regrants(): void
    {
        $first = $this->preparation();
        $data = $this->data($first);
        $grant = $this->issue($data);
        $second = $this->preparation();
        $this->invalid(fn () => $this->issue($this->data($second)));
        $this->invalid(fn () => $this->issue($this->data($first), 'different-token'));
        $this->invalid(fn () => $this->issue($this->data($second, ['uuid' => $data->uuid]), 'different-token'));
        $this->assertDatabaseCount('checkout_token_grants', 1);
    }

    public function test_outer_transaction_cannot_return_an_uncommitted_grant(): void
    {
        $data = $this->data($this->preparation());
        DB::beginTransaction();
        try {
            $this->invalid(fn () => $this->issue($data));
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('checkout_token_grants', 0);
    }

    private function claim(PaymentDispatchPreparation $prep): PaymentDispatchRequest
    {
        $f = $prep->prepared_scope;
        $facts = ['preparation_uuid' => $prep->uuid, 'operation_uuid' => $f['operation_uuid'], 'gateway_account_binding_id' => $this->binding->id,
            'canonical_account_key' => $this->binding->canonical_account_key, 'environment' => 'sandbox', 'merchant_reference' => $f['merchant_reference'],
            'purpose' => 'sale', 'amount_minor' => 2500, 'currency' => 'USD', 'original_transaction_id' => null,
            'merchant_binding_fingerprint' => $this->intent->merchant_binding_fingerprint, 'original_evidence_fingerprint' => null, 'parent_preparation_id' => null];
        $attempt = PaymentDispatchAttempt::create(['uuid' => (string) Str::uuid(), 'payment_dispatch_preparation_id' => $prep->id, 'payment_intent_id' => $this->intent->id,
            'payment_operation_id' => $prep->payment_operation_id, 'executor_key' => 'local.checkout', 'transport_key' => 'authorize_net.xml.v1', 'status' => 'claimed',
            'request_fingerprint' => app(PaymentLedgerScope::class)->fingerprint($facts), 'request_facts' => $facts, 'claimed_at' => CarbonImmutable::now()]);
        PaymentTransportInvocation::create(['payment_dispatch_attempt_id' => $attempt->id, 'claimed_at' => CarbonImmutable::now()]);

        return new PaymentDispatchRequest($attempt->uuid, $prep->uuid, $facts['operation_uuid'], $this->binding->id, $this->binding->canonical_account_key, 'sandbox', $facts['merchant_reference'], 'sale', 2500, 'USD', null, $attempt->request_fingerprint, $this->intent->merchant_binding_fingerprint);
    }

    public function test_consumption_commit_and_second_resolver_cannot_return_token_again(): void
    {
        $prep = $this->preparation();
        $grant = $this->issue($this->data($prep));
        $request = $this->claim($prep);
        $result = $this->resolver($grant)->authorize($request, $this->intent->customer_uuid);
        $this->assertSame('synthetic-checkout-token', $result->value());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('checkout_token_consumptions', 1);
        $this->invalid(fn () => $this->resolver($grant)->authorize($request, $this->intent->customer_uuid));
        $this->assertDatabaseCount('checkout_token_consumptions', 1);
    }

    public function test_consumption_storage_failure_returns_no_token_and_preserves_dispatch_claims(): void
    {
        $prep = $this->preparation();
        $grant = $this->issue($this->data($prep));
        $request = $this->claim($prep);
        $sql = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER fail_checkout_consumption BEFORE INSERT ON checkout_token_consumptions BEGIN SELECT RAISE(ABORT, 'synthetic storage failure'); END"
            : "CREATE TRIGGER fail_checkout_consumption BEFORE INSERT ON checkout_token_consumptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic storage failure'";
        DB::unprepared($sql);
        try {
            $this->invalid(fn () => $this->resolver($grant)->authorize($request, $this->intent->customer_uuid));
        } finally {
            DB::unprepared('DROP TRIGGER fail_checkout_consumption');
        }
        $this->assertDatabaseCount('checkout_token_consumptions', 0);
        $this->assertDatabaseCount('payment_dispatch_attempts', 1);
        $this->assertDatabaseCount('payment_transport_invocations', 1);
        Http::assertNothingSent();
    }

    public function test_crash_after_consumption_commit_cannot_return_token_on_replay(): void
    {
        $prep = $this->preparation();
        $grant = $this->issue($this->data($prep));
        $request = $this->claim($prep);
        CheckoutTokenConsumption::created(fn () => DB::afterCommit(fn () => throw new \RuntimeException('synthetic response loss after durable consume')));
        $this->invalid(fn () => $this->resolver($grant)->authorize($request, $this->intent->customer_uuid));
        $this->assertDatabaseCount('checkout_token_consumptions', 1);
        $this->invalid(fn () => $this->resolver($grant)->authorize($request, $this->intent->customer_uuid));
        $this->assertDatabaseCount('checkout_token_consumptions', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('consumptionFailures')]
    public function test_consumption_rechecks_current_session_quote_token_and_attempt(string $case): void
    {
        $prep = $this->preparation();
        $grant = $this->issue($this->data($prep));
        $request = $this->claim($prep);
        $token = 'synthetic-checkout-token';
        $customer = $this->intent->customer_uuid;
        switch ($case) {
            case 'revoked':$this->patient->tokens()->delete();
                break;
            case 'changed-session':$this->bearer = $this->patient->createToken('new-session', ['patient:*'])->plainTextToken;
                break;
            case 'expired-grant':CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
                $this->beforeApplicationDestroyed(fn () => CarbonImmutable::setTestNow());
                break;
            case 'wrong-token':$token = 'different-token';
                break;
            case 'wrong-customer':$customer = (string) Str::uuid();
                break;
            case 'quote-drift':$this->order->update(['total_amount' => '26.00']);
                break;
            case 'credential-drift':$this->merchant->update(['authnet_transaction_key' => 'changed']);
                break;
            case 'missing-invocation':DB::table('payment_transport_invocations')->delete();
                break;
            case 'disabled':config()->set('payments.checkout_token_authorization_enabled', false);
                break;
        }
        $this->invalid(fn () => $this->resolver($grant, $token)->authorize($request, $customer));
        $this->assertDatabaseCount('checkout_token_consumptions', 0);
        Http::assertNothingSent();
    }

    public static function consumptionFailures(): array
    {
        return array_map(fn ($case) => [$case], ['revoked', 'changed-session', 'expired-grant', 'wrong-token', 'wrong-customer', 'quote-drift', 'credential-drift', 'missing-invocation', 'disabled']);
    }
}
