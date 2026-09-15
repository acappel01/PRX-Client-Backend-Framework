<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\DispatchPreparedPaymentAction;
use App\Contracts\Payments\AuthorizeNetInstrumentAuthorization;
use App\Contracts\Payments\PaymentDispatchTransport;
use App\Data\Payments\AuthorizeNetOpaqueAuthorization;
use App\Data\Payments\PaymentDispatchRequest;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Services\Payments\AuthorizeNetDispatchTransport;
use App\Services\Payments\AuthorizeNetMutationXml;
use App\Services\Payments\PaymentLedgerScope;
use App\Services\Payments\PaymentOperationReferenceScope;
use App\Services\Payments\ResolveOrderFinancialEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFinancialFixture;
use Tests\TestCase;

/** Concrete XML adapter + actual association/reporting/reconciliation, with all HTTP faked. */
class GatewayFinancialIntegrationTest extends TestCase
{
    use PaymentFinancialFixture;

    private Factory $wire;

    private ?\Throwable $wireFailure = null;

    protected function dispatchOwned(PaymentDispatchPreparation $preparation): PaymentDispatchAttempt
    {
        config()->set('payments.authorize_net_transport_enabled', true);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $this->wire = new Factory;
        $this->wire->preventStrayRequests();
        $this->wire->fake(function ($request) {
            try {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertDatabaseCount('payment_dispatch_attempts', 1);
                $this->assertDatabaseCount('payment_transport_invocations', 1);
                $this->assertStringContainsString('<transactionType>authCaptureTransaction</transactionType>', $request->body());
                $this->assertStringContainsString('<amount>25.00</amount>', $request->body());
                $this->assertStringContainsString('<opaqueData>', $request->body());
                $xml = new \DOMDocument;
                @$xml->loadXML($request->body());
                $ref = $xml->getElementsByTagName('refId')->item(0)->textContent;
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

                return $this->wire->response('<createTransactionResponse xmlns="'.AuthorizeNetMutationXml::NS.'"><refId>'.$ref.'</refId><messages><resultCode>Ok</resultCode></messages><transactionResponse><responseCode>1</responseCode><transId>9001</transId></transactionResponse></createTransactionResponse>');
            } catch (\Throwable $error) {
                $this->wireFailure = $error;
                throw $error;
            }
        });
        $authorization = new class implements AuthorizeNetInstrumentAuthorization
        {
            public function authorize(PaymentDispatchRequest $request, string $customerUuid): AuthorizeNetOpaqueAuthorization
            {
                return new AuthorizeNetOpaqueAuthorization($request->request_fingerprint, $request->preparation_uuid,
                    $request->canonical_account_key, $customerUuid, CarbonImmutable::now()->addMinute(), 'synthetic-ephemeral-token');
            }
        };
        $adapter = new AuthorizeNetDispatchTransport(app(PaymentOperationReferenceScope::class), app(PaymentLedgerScope::class), $authorization, $this->wire);
        $this->app->instance(PaymentDispatchTransport::class, $adapter);

        $attempt = app(DispatchPreparedPaymentAction::class)->execute($preparation->uuid, 'local.checkout');
        if ($this->wireFailure !== null) {
            throw $this->wireFailure;
        }
        $this->assertSame('response_observed', $attempt->status);

        return $attempt;
    }

    public function test_concrete_sale_receipt_reconciles_to_reported_order_amount_without_a_second_mutation(): void
    {
        $before = $this->order->fresh()->getAttributes();
        $preparation = $this->rootOwned();
        $financial = $this->financial($preparation);
        $order = app(ResolveOrderFinancialEvidence::class)->execute($this->order->uuid);
        $this->assertTrue($financial->qualified_reported_amounts);
        $this->assertTrue($order->qualified_reported_amounts);
        $this->assertSame(2500, $order->amounts['net_settled_minor']);
        $this->assertSame('sandbox', $order->environment);
        $attempt = app(DispatchPreparedPaymentAction::class)->execute($preparation->uuid, 'local.checkout');
        $this->assertSame('authorize_net.xml.v1', $attempt->transport_key);
        $this->wire->assertSentCount(1);
        $this->assertDatabaseCount('payment_transport_invocations', 1);
        $this->assertStringNotContainsString('synthetic-ephemeral-token', $attempt->getRawOriginal('request_facts'));
        $this->assertStringNotContainsString('synthetic-ephemeral-token', $attempt->getRawOriginal('receipt'));
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->assertDatabaseCount('canonical_events', 0);
    }
}
