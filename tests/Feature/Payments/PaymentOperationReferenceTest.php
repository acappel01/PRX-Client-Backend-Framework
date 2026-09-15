<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreparePaymentIntentAction;
use App\Actions\Payments\PreparePaymentOperationAction;
use App\Actions\Payments\RecordPaymentUncertaintyAction;
use App\Actions\Payments\ReservePaymentOperationReferenceAction;
use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Data\Payments\GatewayAccountBindingData;
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
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentOperationReference;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\CorrelateAuthorizeNetOperation;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentReferenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentOperationReferenceTest extends TestCase
{
    private MerchantAccount $merchant;

    private Order $order;

    private GatewayAccountBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function merchantXml(): string
    {
        return '<getMerchantDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><isTestMode>false</isTestMode><gatewayId>123</gatewayId><currencies><currency>USD</currency></currencies></getMerchantDetailsResponse>';
    }

    private function operation(PaymentOperationPurpose $purpose = PaymentOperationPurpose::Sale): PaymentOperation
    {
        $intent = app(PreparePaymentIntentAction::class)->execute(new PaymentIntentData((string) Str::uuid(), $this->order->id, $this->order->customer_id, $this->merchant->id, GatewayProvider::AuthorizeNet, GatewayEnvironment::Sandbox, 2500, 'USD', 'local.checkout'));

        return app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData((string) Str::uuid(), $intent->uuid, $purpose, 2500, 'local.checkout'));
    }

    private function reserve(PaymentOperation $operation): PaymentOperationReference
    {
        return app(ReservePaymentOperationReferenceAction::class)->execute($operation->uuid, $this->binding->id);
    }

    private function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected refusal.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function uncertainty(PaymentOperation $operation): void
    {
        app(RecordPaymentUncertaintyAction::class)->execute(new PaymentUncertaintyData($operation->uuid, PaymentUncertaintyReason::TransportTimeout, CarbonImmutable::now()));
    }

    private function fakeRead(string $reference, ?callable $during = null, string $type = 'authCaptureTransaction'): void
    {
        Http::fake(function ($request) use ($reference, $during, $type) {
            if (str_contains($request->body(), 'getMerchantDetailsRequest')) {
                return Http::response($this->merchantXml());
            }
            if ($during) {
                $during();
            }
            $status = $type === 'authOnlyTransaction' ? 'authorizedPendingCapture' : 'settledSuccessfully';

            return Http::response('<getTransactionDetailsResponse xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages><transaction><transId>6001</transId><transactionType>'.$type.'</transactionType><transactionStatus>'.$status.'</transactionStatus><authAmount>25.00</authAmount><settleAmount>25.00</settleAmount></transaction><transrefId>'.$reference.'</transrefId></getTransactionDetailsResponse>');
        });
    }

    public function test_reservation_replays_full_operation_identity_without_side_effects(): void
    {
        $operation = $this->operation();
        $before = $this->order->fresh()->getAttributes();
        $reference = $this->reserve($operation);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{20}\z/', $reference->reference);
        $this->assertSame($reference->id, $this->reserve($operation)->id);
        $this->assertSame($operation->id, $reference->payment_operation_id);
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->assertArrayNotHasKey('reference', $reference->toArray());
        $this->assertDatabaseCount('payment_operation_references', 1);
        $this->assertDatabaseCount('canonical_events', 0);
        Http::assertNothingSent();
    }

    public function test_collision_retries_without_reusing_another_operations_reference(): void
    {
        $one = $this->reserve($this->operation());
        $two = $this->operation();
        $this->mock(PaymentReferenceGenerator::class)->shouldReceive('generate')->twice()->andReturn($one->reference, str_repeat('f', 20));
        $reference = $this->reserve($two);
        $this->assertSame(str_repeat('f', 20), $reference->reference);
        $this->assertDatabaseCount('payment_operation_references', 2);
        Http::assertNothingSent();
    }

    public function test_exhausted_collision_leaves_no_partial_reservation(): void
    {
        $one = $this->reserve($this->operation());
        $two = $this->operation();
        $this->mock(PaymentReferenceGenerator::class)->shouldReceive('generate')->times(5)->andReturn($one->reference);
        $this->invalid(fn () => $this->reserve($two));
        $this->assertDatabaseCount('payment_operation_references', 1);
    }

    public function test_uncertain_operation_cannot_gain_new_reference_but_prior_identity_replays(): void
    {
        $one = $this->operation();
        $reference = $this->reserve($one);
        $this->uncertainty($one);
        $this->assertSame($reference->id, $this->reserve($one)->id);
        $two = $this->operation();
        $this->uncertainty($two);
        $this->invalid(fn () => $this->reserve($two));
    }

    public function test_binding_and_scope_drift_refuse_new_reservation_but_do_not_erase_history(): void
    {
        $one = $this->operation();
        $reference = $this->reserve($one);
        $two = $this->operation();
        $this->invalid(fn () => app(ReservePaymentOperationReferenceAction::class)->execute($one->uuid, $this->binding->id + 1));
        $this->merchant->update(['authnet_transaction_key' => 'changed']);
        $this->invalid(fn () => $this->reserve($two));
        $this->assertSame($reference->id, $this->reserve($one)->id);
    }

    public function test_reference_history_is_immutable(): void
    {
        $reference = $this->reserve($this->operation());
        foreach (['update', 'delete'] as $action) {
            try {
                $action === 'update' ? $reference->update(['reference' => str_repeat('a', 20)]) : $reference->delete();
                $this->fail('Expected immutability.');
            } catch (\LogicException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_correlates_sale_and_authorization_without_operation_or_currency_verification(): void
    {
        foreach ([PaymentOperationPurpose::Sale, PaymentOperationPurpose::Authorize] as $purpose) {
            $this->resetHttp();
            $operation = $this->operation($purpose);
            $reference = $this->reserve($operation);
            $this->fakeRead($reference->reference, type: $purpose === PaymentOperationPurpose::Sale ? 'authCaptureTransaction' : 'authOnlyTransaction');
            $before = $operation->fresh()->getAttributes();
            $result = app(CorrelateAuthorizeNetOperation::class)->execute($reference->id, '6001');
            $this->assertSame('reference_matched_only', $result->status);
            $this->assertFalse($result->operation_verified);
            $this->assertFalse($result->read->transaction_currency_verified);
            $this->assertSame($reference->reference, $result->read->merchant_reference);
            $this->assertSame($before, $operation->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('canonical_events', 0);
    }

    public function test_wrong_reference_cannot_be_correlated_by_matching_amount(): void
    {
        $reference = $this->reserve($this->operation());
        $this->fakeRead('other-reference');
        $this->invalid(fn () => app(CorrelateAuthorizeNetOperation::class)->execute($reference->id, '6001'));
    }

    public function test_scope_change_during_read_refuses_correlation(): void
    {
        $reference = $this->reserve($this->operation());
        $this->fakeRead($reference->reference, fn () => $this->order->update(['total_amount' => '26.00']));
        $this->invalid(fn () => app(CorrelateAuthorizeNetOperation::class)->execute($reference->id, '6001'));
    }

    public function test_caller_transaction_refused_before_http(): void
    {
        $reference = $this->reserve($this->operation());
        DB::transaction(fn () => $this->invalid(fn () => app(CorrelateAuthorizeNetOperation::class)->execute($reference->id, '6001')));
        Http::assertNothingSent();
    }

    public function test_capture_reference_does_not_imply_supported_capture_actor_correlation(): void
    {
        $authorization = $this->operation(PaymentOperationPurpose::Authorize);
        $capture = app(PreparePaymentOperationAction::class)->execute(new PaymentOperationData(
            (string) Str::uuid(), PaymentIntent::findOrFail($authorization->payment_intent_id)->uuid,
            PaymentOperationPurpose::Capture, 2500, 'local.checkout', $authorization->uuid,
        ));
        $reference = $this->reserve($capture);
        $this->invalid(fn () => app(CorrelateAuthorizeNetOperation::class)->execute($reference->id, '6001'));
        Http::assertNothingSent();
    }
}
