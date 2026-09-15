<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\DispatchPreparedPaymentAction;
use App\Actions\Payments\PreparePaymentDispatchAction;
use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\ReservePaymentOperationReferenceAction;
use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Contracts\Payments\AuthorizeNetInstrumentAuthorization;
use App\Contracts\Payments\PaymentDispatchTransport;
use App\Data\Payments\AuthorizeNetOpaqueAuthorization;
use App\Data\Payments\GatewayAccountBindingData;
use App\Data\Payments\PaymentDispatchOriginal;
use App\Data\Payments\PaymentDispatchPreparationData;
use App\Data\Payments\PaymentDispatchRequest;
use App\Data\Payments\PaymentIntentData;
use App\Data\Payments\PaymentOperationData;
use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentIntent;
use App\Services\Payments\AuthorizeNetDispatchTransport;
use App\Services\Payments\AuthorizeNetMutationXml;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationReferenceScope;
use App\Services\Payments\ResolvePaymentDispatchOriginal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthorizeNetDispatchTransportTest extends TestCase
{
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
        $customer = Customer::factory()->create();
        $this->order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $this->merchant = MerchantAccount::factory()->create();
        Http::fake(fn () => Http::response('<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>123</gatewayId><currencies><currency>USD</currency></currencies></getMerchantDetailsResponse>'));
        $this->binding = app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '123', 'USD'));
        $this->intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $customer->id, $this->merchant->id, GatewayProvider::AuthorizeNet, GatewayEnvironment::Sandbox, 2500, 'USD', 'local.checkout'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function runTransport(?\Closure $fake = null, ?AuthorizeNetInstrumentAuthorization $authorization = null): array
    {
        config()->set('payments.dispatch_enabled', true);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake($fake ?? fn ($request) => $http->response($this->response($this->referenceFrom($request->body()))));
        $transport = new AuthorizeNetDispatchTransport(app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class), $authorization ?? $this->authorization(), $http);
        $operation = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $this->intent->uuid, PaymentOperationPurpose::Sale, 2500, 'local.checkout'));
        $reference = app(ReservePaymentOperationReferenceAction::class)->execute($operation->uuid, $this->binding->id);
        $prep = app(PreparePaymentDispatchAction::class)->execute(new PaymentDispatchPreparationData((string) Str::uuid(), $reference->id, 'local.checkout'));
        $action = new DispatchPreparedPaymentAction($transport, app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class));

        return [$action, $prep, $http, $transport];
    }

    private function authorization(?\Closure $callback = null): AuthorizeNetInstrumentAuthorization
    {
        return new class($callback) implements AuthorizeNetInstrumentAuthorization
        {
            public function __construct(private ?\Closure $callback) {}

            public function authorize(PaymentDispatchRequest $request, string $customerUuid): AuthorizeNetOpaqueAuthorization
            {
                return $this->callback ? ($this->callback)($request, $customerUuid) : new AuthorizeNetOpaqueAuthorization($request->request_fingerprint, $request->preparation_uuid, $request->canonical_account_key, $customerUuid, CarbonImmutable::now()->addMinute(), 'synthetic-token-only');
            }
        };
    }

    private function referenceFrom(string $body): string
    {
        $doc = new \DOMDocument;
        @$doc->loadXML($body);

        return $doc->getElementsByTagName('refId')->item(0)->textContent;
    }

    private function response(string $ref, string $extra = '', string $code = '1', string $id = '9001'): string
    {
        return '<createTransactionResponse xmlns="'.AuthorizeNetMutationXml::NS.'"><refId>'.$ref.'</refId><messages><resultCode>Ok</resultCode></messages><transactionResponse><responseCode>'.$code.'</responseCode><transId>'.$id.'</transId>'.$extra.'</transactionResponse></createTransactionResponse>';
    }

    private function dto(PaymentDispatchAttempt $attempt): PaymentDispatchRequest
    {
        $f = $attempt->request_facts;

        return new PaymentDispatchRequest($attempt->uuid, $f['preparation_uuid'], $f['operation_uuid'], $f['gateway_account_binding_id'], $f['canonical_account_key'], $f['environment'], $f['merchant_reference'], $f['purpose'], $f['amount_minor'], $f['currency'], $f['original_transaction_id'], $attempt->request_fingerprint, $f['merchant_binding_fingerprint']);
    }

    public function test_adapter_requires_separate_gate_and_never_uses_default_transport(): void
    {
        [$action,$prep,$http] = $this->runTransport();
        $attempt = $action->execute($prep->uuid, 'local.checkout');
        $this->assertSame('outcome_unknown', $attempt->status);
        $http->assertNothingSent();
        $this->assertDatabaseCount('payment_transport_invocations', 0);
        $this->assertFalse(app()->bound(PaymentDispatchTransport::class));
        $this->assertFalse(app()->bound(AuthorizeNetInstrumentAuthorization::class));
    }

    public function test_exact_opaque_request_is_sent_once_after_committed_claim_and_response_is_minimal(): void
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        [$action,$prep,$http,$transport] = $this->runTransport(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('payment_transport_invocations', 1);
            $doc = new \DOMDocument;
            @$doc->loadXML($request->body());
            $this->assertSame('25.00', $doc->getElementsByTagName('amount')->item(0)->textContent);
            $this->assertSame('USD', $doc->getElementsByTagName('currencyCode')->item(0)->textContent);
            $this->assertSame('authCaptureTransaction', $doc->getElementsByTagName('transactionType')->item(0)->textContent);
            $this->assertSame('COMMON.ACCEPT.INAPP.PAYMENT', $doc->getElementsByTagName('dataDescriptor')->item(0)->textContent);
            $this->assertSame(0, $doc->getElementsByTagName('profile')->length);

            return (new Factory)->response($this->response($this->referenceFrom($request->body()), '<accountNumber>XXXX1111</accountNumber><authCode>SECRET</authCode>'));
        });
        $attempt = $action->execute($prep->uuid, 'local.checkout');
        $this->assertSame('response_observed', $attempt->status);
        $this->assertSame('9001', $attempt->receipt['transaction_id']);
        $this->assertArrayNotHasKey('accountNumber', $attempt->receipt);
        $this->assertSame($attempt->id, $action->execute($prep->uuid, 'local.checkout')->id);
        try {
            $transport->dispatch($this->dto($attempt));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertNull($e->getPrevious());
        }
        $http->assertSentCount(1);
        $raw = json_encode(DB::table('payment_dispatch_attempts')->first());
        $this->assertStringNotContainsString('synthetic-token-only', $raw);
        $this->assertStringNotContainsString('SECRET', $raw);
    }

    public function test_expired_or_wrong_customer_authorization_never_sends_and_claim_is_not_reusable(): void
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        [$action,$prep,$http] = $this->runTransport(authorization: $this->authorization(fn ($r, $c) => new AuthorizeNetOpaqueAuthorization($r->request_fingerprint, $r->preparation_uuid, $r->canonical_account_key, 'wrong-customer', CarbonImmutable::now()->subSecond(), 'token')));
        $this->assertSame('outcome_unknown', $action->execute($prep->uuid, 'local.checkout')->status);
        $action->execute($prep->uuid, 'local.checkout');
        $http->assertNothingSent();
        $this->assertDatabaseCount('payment_transport_invocations', 1);
    }

    public function test_authorization_credential_drift_refuses_before_http(): void
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        [$action,$prep,$http] = $this->runTransport(authorization: $this->authorization(function ($r, $c) {
            $this->merchant->update(['authnet_transaction_key' => 'changed-key']);

            return new AuthorizeNetOpaqueAuthorization($r->request_fingerprint, $r->preparation_uuid, $r->canonical_account_key, $c, CarbonImmutable::now()->addMinute(), 'token');
        }));
        $this->assertSame('outcome_unknown', $action->execute($prep->uuid, 'local.checkout')->status);
        $http->assertNothingSent();
        $this->assertDatabaseCount('payment_transport_invocations', 1);
    }

    public function test_response_loss_is_unknown_and_has_no_second_post(): void
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        [$action,$prep,$http] = $this->runTransport(fn () => throw new RuntimeException('secret raw instrument'));
        $attempt = $action->execute($prep->uuid, 'local.checkout');
        $this->assertSame('outcome_unknown', $attempt->status);
        $this->assertNull($attempt->receipt);
        $action->execute($prep->uuid, 'local.checkout');
        $this->assertDatabaseCount('payment_transport_invocations', 1);
    }

    #[DataProvider('malformedResponses')]
    public function test_strict_response_refuses_each_malformed_case(string $case): void
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        [$action, $prep, $http] = $this->runTransport(function ($request) use ($case) {
            $ref = $this->referenceFrom($request->body());
            $body = match ($case) {
                'duplicate-ref' => str_replace('</createTransactionResponse>', '<refId>'.$ref.'</refId></createTransactionResponse>', $this->response($ref)),
                'namespace-ref' => str_replace('<refId>', '<refId xmlns="foreign">', $this->response($ref)),
                'nested-ref' => str_replace('<refId>'.$ref.'</refId>', '<refId><child>'.$ref.'</child></refId>', $this->response($ref)),
                'wrong-ref' => $this->response('wrong'),
                'duplicate-code' => $this->response($ref, '<responseCode>1</responseCode>'),
                'namespace-code' => $this->response($ref, '<responseCode xmlns="foreign">1</responseCode>'),
                'test-mode' => $this->response($ref, '<testRequest>true</testRequest>'),
                'wrong-original' => $this->response($ref, '<refTransID>999</refTransID>'),
                'entity' => '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'.$this->response($ref),
                'approval-without-id' => $this->response($ref, '', '1', '0'),
            };

            return (new Factory)->response($body);
        });
        $attempt = $action->execute($prep->uuid, 'local.checkout');
        $this->assertSame('outcome_unknown', $attempt->status);
        $this->assertNull($attempt->receipt);
        $http->assertSentCount(1);
        $action->execute($prep->uuid, 'local.checkout');
        $http->assertSentCount(1);
    }

    public static function malformedResponses(): array
    {
        return array_map(fn ($case) => [$case], ['duplicate-ref', 'namespace-ref', 'nested-ref', 'wrong-ref', 'duplicate-code', 'namespace-code', 'test-mode', 'wrong-original', 'entity', 'approval-without-id']);
    }

    public function test_authorization_can_disable_gate_before_mutation(): void
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        [$action,$prep,$http] = $this->runTransport(authorization: $this->authorization(function ($r, $c) {
            config()->set('payments.authorize_net_transport_enabled', false);

            return new AuthorizeNetOpaqueAuthorization($r->request_fingerprint, $r->preparation_uuid, $r->canonical_account_key, $c, CarbonImmutable::now()->addMinute(), 'token');
        }));
        $this->assertSame('outcome_unknown', $action->execute($prep->uuid, 'local.checkout')->status);
        $http->assertNothingSent();
    }

    /** Synthetic owned claim + explicitly mocked trusted lineage isolate the wire contract, not parent qualification. */
    private function linkedRequest(string $purpose): PaymentDispatchRequest
    {
        $parentPurpose = $purpose === 'capture' ? PaymentOperationPurpose::Authorize : PaymentOperationPurpose::Sale;
        $parent = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $this->intent->uuid, $parentPurpose, 2500, 'local.checkout'));
        $parentReference = app(ReservePaymentOperationReferenceAction::class)->execute($parent->uuid, $this->binding->id);
        $parentPrep = app(PreparePaymentDispatchAction::class)->execute(new PaymentDispatchPreparationData((string) Str::uuid(), $parentReference->id, 'local.checkout'));
        $operation = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $this->intent->uuid, PaymentOperationPurpose::from($purpose), 2500, 'local.checkout', $parent->uuid));
        $reference = app(ReservePaymentOperationReferenceAction::class)->execute($operation->uuid, $this->binding->id);
        $prep = app(PreparePaymentDispatchAction::class)->execute(new PaymentDispatchPreparationData((string) Str::uuid(), $reference->id, 'local.checkout'));
        $original = new PaymentDispatchOriginal('8001', $parentPrep->id, $parent->id, $this->binding->canonical_account_key, 'sandbox', str_repeat('a', 64));
        $this->mock(ResolvePaymentDispatchOriginal::class)->shouldReceive('resolve')->andReturn($original);
        $facts = ['preparation_uuid' => $prep->uuid, 'operation_uuid' => $operation->uuid, 'gateway_account_binding_id' => $this->binding->id,
            'canonical_account_key' => $this->binding->canonical_account_key, 'environment' => 'sandbox', 'merchant_reference' => $reference->reference,
            'purpose' => $purpose, 'amount_minor' => 2500, 'currency' => 'USD', 'original_transaction_id' => '8001',
            'merchant_binding_fingerprint' => $this->intent->merchant_binding_fingerprint, 'original_evidence_fingerprint' => $original->evidence_fingerprint, 'parent_preparation_id' => $parentPrep->id];
        $attempt = PaymentDispatchAttempt::create(['uuid' => (string) Str::uuid(), 'payment_dispatch_preparation_id' => $prep->id, 'payment_intent_id' => $this->intent->id, 'payment_operation_id' => $operation->id,
            'executor_key' => 'local.checkout', 'transport_key' => 'authorize_net.xml.v1', 'status' => 'claimed', 'request_fingerprint' => app(PaymentLedgerScope::class)->fingerprint($facts), 'request_facts' => $facts, 'claimed_at' => CarbonImmutable::now()]);

        return $this->dto($attempt);
    }

    #[DataProvider('linkedPurposes')]
    public function test_linked_wire_contract_exact_parent_and_transient_refund_card(string $purpose): void
    {
        config()->set('payments.dispatch_enabled', true);
        config()->set('payments.authorize_net_transport_enabled', true);
        $request = $this->linkedRequest($purpose);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function ($wire) use ($http, $request, $purpose) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('payment_transport_invocations', 1);
            $doc = new \DOMDocument;
            @$doc->loadXML($wire->body());
            if ($doc->documentElement->localName === 'getTransactionDetailsRequest') {
                $this->assertSame('8001', $doc->getElementsByTagName('transId')->item(0)->textContent);

                return $http->response('<getTransactionDetailsResponse xmlns="'.AuthorizeNetMutationXml::NS.'"><messages><resultCode>Ok</resultCode></messages><transaction><transId>8001</transId><transactionType>authCaptureTransaction</transactionType><transactionStatus>settledSuccessfully</transactionStatus><settleAmount>25.00</settleAmount><payment><creditCard><cardNumber>XXXX1111</cardNumber></creditCard></payment></transaction></getTransactionDetailsResponse>');
            }
            $this->assertSame('8001', $doc->getElementsByTagName('refTransId')->item(0)->textContent);
            $this->assertSame($request->merchant_reference, $this->referenceFrom($wire->body()));
            $this->assertSame(match ($purpose) {
                'capture' => 'priorAuthCaptureTransaction','void' => 'voidTransaction','refund' => 'refundTransaction'
            }, $doc->getElementsByTagName('transactionType')->item(0)->textContent);
            $this->assertSame($purpose === 'void' ? 0 : 1, $doc->getElementsByTagName('amount')->length);
            if ($purpose === 'refund') {
                $this->assertSame('1111', $doc->getElementsByTagName('cardNumber')->item(0)->textContent);
                $this->assertSame('XXXX', $doc->getElementsByTagName('expirationDate')->item(0)->textContent);
            } else {
                $this->assertSame(0, $doc->getElementsByTagName('payment')->length);
            }

            return $http->response($this->response($request->merchant_reference, '<refTransID>8001</refTransID>', '1', $purpose === 'refund' ? '9001' : '8001'));
        });
        $transport = new AuthorizeNetDispatchTransport(app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class), null, $http);
        $receipt = $transport->dispatch($request);
        $this->assertSame('1', $receipt->response_code);
        $http->assertSentCount($purpose === 'refund' ? 2 : 1);
        try {
            $transport->dispatch($request);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertNull($e->getPrevious());
        }
        $http->assertSentCount($purpose === 'refund' ? 2 : 1);
    }

    #[DataProvider('refundFailures')]
    public function test_refund_read_never_supplies_unqualified_card_or_stale_scope(string $case): void
    {
        config()->set('payments.dispatch_enabled', true);
        config()->set('payments.authorize_net_transport_enabled', true);
        $request = $this->linkedRequest('refund');
        $http = new Factory;
        $http->preventStrayRequests();
        $calls = 0;
        $http->fake(function ($wire) use ($http, $case, &$calls) {
            $calls++;
            $this->assertStringContainsString('getTransactionDetailsRequest', $wire->body());
            $id = $case === 'wrong-transaction' ? '999' : '8001';
            $amount = $case === 'insufficient-amount' ? '24.99' : '25.00';
            $mask = match ($case) {
                'full-card' => '4111111111111111','unmasked-last4' => '1111','foreign-card' => 'XXXX1111</cardNumber><cardNumber xmlns="foreign">XXXX1111',default => 'XXXX1111'
            };
            $status = $case === 'unsettled' ? 'capturedPendingSettlement' : 'settledSuccessfully';
            if ($case === 'credential-drift') {
                $this->merchant->update(['authnet_transaction_key' => 'drift']);
            }
            if ($case === 'disabled-during-read') {
                config()->set('payments.dispatch_enabled', false);
            }
            if ($case === 'lineage-drift') {
                $this->mock(ResolvePaymentDispatchOriginal::class)->shouldReceive('resolve')->andThrow(ValidationException::withMessages(['payment' => 'conflicting parent']));
            }

            return $http->response('<getTransactionDetailsResponse xmlns="'.AuthorizeNetMutationXml::NS.'"><messages><resultCode>Ok</resultCode></messages><transaction><transId>'.$id.'</transId><transactionType>authCaptureTransaction</transactionType><transactionStatus>'.$status.'</transactionStatus><settleAmount>'.$amount.'</settleAmount><payment><creditCard><cardNumber>'.$mask.'</cardNumber></creditCard></payment></transaction></getTransactionDetailsResponse>');
        });
        $transport = new AuthorizeNetDispatchTransport(app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class), null, $http);
        try {
            $transport->dispatch($request);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(1, $calls);
        $http->assertSentCount(1);
        $this->assertDatabaseCount('payment_transport_invocations', 1);
    }

    public static function refundFailures(): array
    {
        return array_map(fn ($case) => [$case], ['wrong-transaction', 'insufficient-amount', 'full-card', 'unmasked-last4', 'foreign-card', 'unsettled', 'credential-drift', 'disabled-during-read', 'lineage-drift']);
    }

    public static function linkedPurposes(): array
    {
        return [['capture'], ['void'], ['refund']];
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_are_unknown_and_never_retried(string $body, int $status, array $headers): void
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        [$action,$prep,$http] = $this->runTransport(fn () => (new Factory)->response($body, $status, $headers));
        $attempt = $action->execute($prep->uuid, 'local.checkout');
        $this->assertSame('outcome_unknown', $attempt->status);
        $this->assertNull($attempt->receipt);
        $http->assertSentCount(1);
        $action->execute($prep->uuid, 'local.checkout');
        $http->assertSentCount(1);
    }

    public static function httpFailures(): array
    {
        return [[str_repeat('x', AuthorizeNetMutationXml::MAX_BYTES + 1), 200, []], ['body', 200, ['Content-Encoding' => 'gzip']], ['body', 302, ['Location' => 'https://example.invalid']]];
    }
}
