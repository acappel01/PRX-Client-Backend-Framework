<?php

namespace Tests\Support;

use App\Actions\Payments\DispatchPreparedPaymentAction;
use App\Actions\Payments\PreparePaymentDispatchAction;
use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordAuthorizeNetAssociationAction;
use App\Actions\Payments\RecordAuthorizeNetOperationLineageAction;
use App\Actions\Payments\RecordPaymentFinancialObservationAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Actions\Payments\ReservePaymentOperationReferenceAction;
use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Contracts\Payments\PaymentDispatchTransport;
use App\Data\Payments\GatewayAccountBindingData;
use App\Data\Payments\PaymentDispatchPreparationData;
use App\Data\Payments\PaymentDispatchReceipt;
use App\Data\Payments\PaymentDispatchRequest;
use App\Data\Payments\PaymentFinancialResolution;
use App\Data\Payments\PaymentIntentData;
use App\Data\Payments\PaymentOperationData;
use App\Data\Payments\PaymentUncertaintyData;
use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Enums\Payments\PaymentOperationPurpose;
use App\Enums\Payments\PaymentUncertaintyReason;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\ResolvePaymentFinancialEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait PaymentFinancialFixture
{
    protected MerchantAccount $merchant;

    protected Order $order;

    protected GatewayAccountBinding $binding;

    protected string $processorXml = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.dispatch_enabled' => true]);
        Bus::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15T12:00:00.123456Z'));
        $this->beforeApplicationDestroyed(fn () => CarbonImmutable::setTestNow());
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        $this->mock(PaymentGatewayManager::class, function ($mock): void {
            $mock->shouldNotReceive('forAccount', 'forAccountId', 'driver', 'default');
        });
        $customer = Customer::factory()->create();
        $this->order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USD', 'total_amount' => '25.00']);
        $this->merchant = MerchantAccount::factory()->create();
        Http::fake(['*' => Http::response($this->merchantXml())]);
        $this->binding = app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '123', 'USD', $this->merchant->provider_merchant_profile_id));
        $this->resetHttp();
    }

    protected function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function merchantXml(): string
    {
        return '<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>123</gatewayId><currencies><currency>USD</currency></currencies>'.$this->processorXml.'</getMerchantDetailsResponse>';
    }

    protected function operation(PaymentOperationPurpose $purpose = PaymentOperationPurpose::Sale): PaymentOperation
    {
        $intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $this->order->customer_id, $this->merchant->id, GatewayProvider::AuthorizeNet, $this->merchant->environment, 2500, 'USD', 'local.checkout'));

        return app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $intent->uuid, $purpose, 2500, 'local.checkout'));
    }

    protected function reserve(PaymentOperation $operation): PaymentOperationReference
    {
        return app(ReservePaymentOperationReferenceAction::class)->execute($operation->uuid, $this->binding->id);
    }

    protected function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected refusal.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    protected function uncertainty(PaymentOperation $operation): void
    {
        app(RecordPaymentUncertaintyAction::class)->execute(new PaymentUncertaintyData($operation->uuid, PaymentUncertaintyReason::TransportTimeout, CarbonImmutable::now()));
    }

    protected function fakeRead(string $reference, ?callable $during = null, string $type = 'authCaptureTransaction', string $transactionId = '6001', string $submitted = '2026-09-15T12:00:01.123456Z', string $rail = 'creditCard'): void
    {
        Http::fake(function ($request) use ($reference, $during, $type, $transactionId, $submitted, $rail) {
            if (str_contains($request->body(), 'getMerchantDetailsRequest')) {
                return Http::response($this->merchantXml());
            }
            if ($during) {
                $during();
            }
            $status = $type === 'authOnlyTransaction' ? 'authorizedPendingCapture' : 'settledSuccessfully';

            return Http::response('<getTransactionDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><transaction><transId>'.$transactionId.'</transId><submitTimeUTC>'.$submitted.'</submitTimeUTC><payment><'.$rail.'/></payment><transactionType>'.$type.'</transactionType><transactionStatus>'.$status.'</transactionStatus><authAmount>25.00</authAmount><settleAmount>25.00</settleAmount></transaction><transrefId>'.$reference.'</transrefId></getTransactionDetailsResponse>');
        });
    }

    protected function prepare(?PaymentOperation $operation = null): PaymentDispatchPreparation
    {
        $reference = $this->reserve($operation ?? $this->operation());

        return app(PreparePaymentDispatchAction::class)->execute(new PaymentDispatchPreparationData((string) Str::uuid(), $reference->id, 'local.checkout'));
    }

    protected function record($preparation, string $transactionId = '6001')
    {
        return app(RecordAuthorizeNetAssociationAction::class)->execute($preparation->id, $transactionId);
    }

    protected function readFor($preparation, string $transactionId = '6001', ?callable $during = null): void
    {
        $this->resetHttp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15T12:01:00.123456Z'));
        $this->fakeRead($preparation->prepared_scope['merchant_reference'], $during, transactionId: $transactionId);
    }

    protected array $entities = [];

    protected int $nextTransaction = 8000;

    protected function dispatchOwned(PaymentDispatchPreparation $preparation): PaymentDispatchAttempt
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $id = (string) ++$this->nextTransaction;
        $transport = new class($id) implements PaymentDispatchTransport
        {
            public function __construct(private string $id) {}

            public function key(): string
            {
                return 'synthetic.lineage';
            }

            public function supports(string $purpose): bool
            {
                return true;
            }

            public function dispatch(PaymentDispatchRequest $request): PaymentDispatchReceipt
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Transport must be outside transaction.');
                }
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

                return new PaymentDispatchReceipt($request->request_fingerprint, '1',
                    in_array($request->purpose, ['capture', 'void'], true) ? $request->original_transaction_id : $this->id,
                    $request->merchant_reference, $request->original_transaction_id);
            }
        };
        $this->app->instance(PaymentDispatchTransport::class, $transport);

        return app(DispatchPreparedPaymentAction::class)->execute($preparation->uuid, 'local.checkout');
    }

    protected function reports(?callable $during = null): void
    {
        $this->resetHttp();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        Http::fake(function ($request) use ($during) {
            $this->assertSame(0, DB::transactionLevel());
            if (str_contains($request->body(), 'getMerchantDetailsRequest')) {
                return Http::response($this->merchantXml());
            }
            preg_match('/<transId>([0-9]+)<\/transId>/', $request->body(), $match);
            $entity = $this->entities[$match[1]];
            if ($during) {
                $during();
            }
            $original = $entity['original'] === null ? '' : '<refTransId>'.$entity['original'].'</refTransId>';

            return Http::response('<getTransactionDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><transaction><transId>'.$match[1].'</transId><submitTimeUTC>'.$entity['submitted'].'</submitTimeUTC><payment><'.$entity['rail'].'/></payment><transactionType>'.$entity['type'].'</transactionType><transactionStatus>'.$entity['status'].'</transactionStatus><authAmount>'.number_format($entity['auth'] / 100, 2, '.', '').'</authAmount><settleAmount>'.number_format($entity['settle'] / 100, 2, '.', '').'</settleAmount>'.$original.'</transaction><transrefId>'.$entity['reference'].'</transrefId></getTransactionDetailsResponse>');
        });
    }

    protected function rootOwned(PaymentOperationPurpose $purpose = PaymentOperationPurpose::Sale): PaymentDispatchPreparation
    {
        $preparation = $this->prepare($this->operation($purpose));
        $attempt = $this->dispatchOwned($preparation);
        $id = $attempt->receipt['transaction_id'];
        $this->entities[$id] = ['type' => $purpose === PaymentOperationPurpose::Sale ? 'authCaptureTransaction' : 'authOnlyTransaction',
            'status' => $purpose === PaymentOperationPurpose::Sale ? 'settledSuccessfully' : 'authorizedPendingCapture',
            'submitted' => CarbonImmutable::now()->subSecond()->format('Y-m-d\TH:i:s.u\Z'), 'auth' => 2500, 'settle' => 2500,
            'reference' => $preparation->prepared_scope['merchant_reference'], 'original' => null, 'rail' => 'creditCard'];
        $this->reports();
        $this->assertSame('associated_only', $this->record($preparation, $id)->status);

        return $preparation;
    }

    protected function childPreparation(PaymentDispatchPreparation $parent, PaymentOperationPurpose $purpose, int $amount = 2500): PaymentDispatchPreparation
    {
        $parentOperation = PaymentOperation::findOrFail($parent->payment_operation_id);
        $operation = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(),
            PaymentIntent::findOrFail($parentOperation->payment_intent_id)->uuid, $purpose, $amount, 'local.checkout', $parentOperation->uuid));

        return $this->prepare($operation);
    }

    protected function childOwned(PaymentDispatchPreparation $parent, PaymentOperationPurpose $purpose, int $amount = 2500): array
    {
        $preparation = $this->childPreparation($parent, $purpose, $amount);
        $attempt = $this->dispatchOwned($preparation);
        $id = $attempt->receipt['transaction_id'];
        $original = $attempt->request_facts['original_transaction_id'];
        if ($purpose === PaymentOperationPurpose::Refund) {
            $this->entities[$original]['status'] = 'settledSuccessfully';
            $this->entities[$id] = ['type' => 'refundTransaction', 'status' => 'refundPendingSettlement',
                'submitted' => CarbonImmutable::now()->subSecond()->format('Y-m-d\TH:i:s.u\Z'), 'auth' => $amount, 'settle' => $amount,
                'reference' => $preparation->prepared_scope['merchant_reference'], 'original' => $original, 'rail' => 'creditCard'];
        } else {
            $this->entities[$id]['status'] = $purpose === PaymentOperationPurpose::Capture ? 'capturedPendingSettlement' : 'voided';
            $this->entities[$id]['settle'] = $purpose === PaymentOperationPurpose::Capture ? $amount : 0;
        }
        $this->reports();

        return [$preparation, $attempt];
    }

    protected function lineage(PaymentDispatchPreparation $preparation, PaymentDispatchAttempt $attempt)
    {
        return app(RecordAuthorizeNetOperationLineageAction::class)->execute($preparation->id, $attempt->id);
    }

    protected function financial(PaymentDispatchPreparation $preparation): PaymentFinancialResolution
    {
        return app(RecordPaymentFinancialObservationAction::class)->execute($preparation->id);
    }

    protected function assessment(PaymentDispatchPreparation $preparation): PaymentFinancialResolution
    {
        return app(ResolvePaymentFinancialEvidence::class)->execute($preparation->id);
    }
}
