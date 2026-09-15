<?php

namespace App\Services\Payments;

use App\Contracts\Payments\AuthorizeNetInstrumentAuthorization;
use App\Contracts\Payments\PaymentDispatchTransport;
use App\Data\Payments\PaymentDispatchReceipt;
use App\Data\Payments\PaymentDispatchRequest;
use App\Enums\Payments\PaymentOperationState;
use App\Models\Commerce\Order;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Models\Payments\PaymentTransportInvocation;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Inactive, unbound concrete adapter. A flag is not authorization to wire or execute payments. */
final class AuthorizeNetDispatchTransport implements PaymentDispatchTransport
{
    private readonly AuthorizeNetMutationXml $xml;

    private readonly Factory $http;

    public function __construct(private readonly PaymentOperationReferenceScope $scope, private readonly PaymentLedgerScope $ledger,
        private readonly ?AuthorizeNetInstrumentAuthorization $authorization = null, ?Factory $http = null)
    {
        // Deliberately avoid the global HTTP dispatcher/middleware which may log request instruments.
        $this->xml = new AuthorizeNetMutationXml;
        $this->http = $http ?? new Factory;
    }

    public function key(): string
    {
        return 'authorize_net.xml.v1';
    }

    public function supports(string $purpose): bool
    {
        return in_array($purpose, ['capture', 'void', 'refund'], true)
            || ($this->authorization !== null && in_array($purpose, ['sale', 'authorize'], true));
    }

    public function dispatch(PaymentDispatchRequest $request): PaymentDispatchReceipt
    {
        try {
            if (DB::transactionLevel() !== 0 || config('payments.dispatch_enabled', false) !== true
                || config('payments.authorize_net_transport_enabled', false) !== true || ! $this->supports($request->purpose)) {
                $this->xml->reject();
            }
            // Commit the unique adapter-entry claim before even a refund reporting read. Crash/replay cannot redeliver.
            [$merchant, $customerUuid] = $this->current($request, true);
            $payment = null;
            $auth = null;
            if (in_array($request->purpose, ['sale', 'authorize'], true)) {
                $auth = $this->authorization->authorize($request, $customerUuid);
                if ($auth->request_fingerprint !== $request->request_fingerprint || $auth->preparation_uuid !== $request->preparation_uuid
                    || $auth->canonical_account_key !== $request->canonical_account_key || $auth->customer_uuid !== $customerUuid
                    || $auth->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                    || ! preg_match('/\A[A-Za-z0-9+\/=_.-]{1,8192}\z/', $auth->value())) {
                    $this->xml->reject();
                }
                $payment = ['opaqueData' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => $auth->value()]];
            } elseif ($request->purpose === 'refund') {
                $payment = ['creditCard' => ['cardNumber' => $this->refundLastFour($request, $merchant), 'expirationDate' => 'XXXX']];
            }
            // Resolve current scope after authorization/refund IO; send only this freshly validated frozen snapshot.
            [$merchant] = $this->current($request, false);
            $doc = $this->document($merchant, 'createTransactionRequest');
            $root = $doc->documentElement;
            $this->add($root, 'refId', $request->merchant_reference);
            $txn = $this->add($root, 'transactionRequest');
            $this->add($txn, 'transactionType', match ($request->purpose) {
                'sale' => 'authCaptureTransaction', 'authorize' => 'authOnlyTransaction', 'capture' => 'priorAuthCaptureTransaction',
                'refund' => 'refundTransaction', 'void' => 'voidTransaction',
            });
            if ($request->purpose !== 'void') {
                $this->add($txn, 'amount', intdiv($request->amount_minor, 100).'.'.str_pad((string) ($request->amount_minor % 100), 2, '0', STR_PAD_LEFT));
                $this->add($txn, 'currencyCode', $request->currency);
            }
            if ($payment !== null) {
                $container = $this->add($this->add($txn, 'payment'), array_key_first($payment));
                foreach (current($payment) as $name => $value) {
                    $this->add($container, $name, $value);
                }
            }
            if ($request->original_transaction_id !== null) {
                $this->add($txn, 'refTransId', $request->original_transaction_id);
            }
            if (config('payments.dispatch_enabled', false) !== true || config('payments.authorize_net_transport_enabled', false) !== true
                || ($auth !== null && $auth->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC')))) {
                $this->xml->reject();
            }
            $xml = $this->exchange($doc->saveXML(), $request->environment, 'createTransactionResponse');
            $base = '/a:createTransactionResponse';
            $this->xml->element($xml, $base, 'messages');
            $result = $this->xml->value($xml, $base.'/a:messages', 'resultCode');
            $this->xml->element($xml, $base, 'transactionResponse');
            $code = $this->xml->value($xml, $base.'/a:transactionResponse', 'responseCode');
            $id = $this->xml->value($xml, $base.'/a:transactionResponse', 'transId', true);
            $ref = $this->xml->value($xml, $base, 'refId');
            $original = $this->xml->value($xml, $base.'/a:transactionResponse', 'refTransID', true);
            $test = $this->xml->value($xml, $base.'/a:transactionResponse', 'testRequest', true);
            if (! in_array($result, ['Ok', 'Error'], true) || ! in_array($code, ['1', '2', '3', '4'], true)
                || $ref !== $request->merchant_reference || ($test !== null && ! in_array($test, ['0', 'false'], true))
                || ($code === '1' && $result !== 'Ok')) {
                $this->xml->reject();
            }
            $id = ($id === null || $id === '0') && $code !== '1' ? null : $id;
            $original = $original === '0' || $original === '' ? null : $original;
            if (($id !== null && ! preg_match('/\A[1-9][0-9]{0,31}\z/', $id)) || ($code === '1' && $id === null)
                || ($original !== null && $original !== $request->original_transaction_id)) {
                $this->xml->reject();
            }

            return new PaymentDispatchReceipt($request->request_fingerprint, $code, $id, $ref, $original);
        } catch (Throwable) {
            $this->xml->reject();
        }
    }

    private function exchange(#[\SensitiveParameter] string $body, string $environment, string $root): DOMXPath
    {
        $stream = null;
        try {
            if (! in_array($environment, ['sandbox', 'production'], true)
                || ! in_array($root, ['createTransactionResponse', 'getTransactionDetailsResponse'], true)
                || config('payments.dispatch_enabled', false) !== true || config('payments.authorize_net_transport_enabled', false) !== true) {
                $this->xml->reject();
            }
            $response = $this->http->withBody($body, 'application/xml')->accept('application/xml')
                ->withHeaders(['Accept-Encoding' => 'identity'])->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->withOptions(['stream' => true, 'decode_content' => false, 'read_timeout' => 5,
                    'on_headers' => function ($response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > AuthorizeNetMutationXml::MAX_BYTES) {
                            $this->xml->reject();
                        }
                    },
                ])->post($environment === 'sandbox' ? 'https://apitest.authorize.net/xml/v1/request.api' : 'https://api.authorize.net/xml/v1/request.api');
            $stream = $response->toPsrResponse()->getBody();
            if ($response->status() !== 200 || filled($response->header('Content-Encoding'))) {
                $this->xml->reject();
            }
            $bytes = '';
            $deadline = hrtime(true) + 15_000_000_000;
            while (! $stream->eof() && strlen($bytes) <= AuthorizeNetMutationXml::MAX_BYTES) {
                if (hrtime(true) > $deadline) {
                    $this->xml->reject();
                }
                $chunk = $stream->read(min(8192, AuthorizeNetMutationXml::MAX_BYTES + 1 - strlen($bytes)));
                if ($chunk === '' && ! $stream->eof()) {
                    $this->xml->reject();
                }
                $bytes .= $chunk;
            }
            if (strlen($bytes) > AuthorizeNetMutationXml::MAX_BYTES || str_contains(strtoupper($bytes), '<!DOCTYPE') || str_contains(strtoupper($bytes), '<!ENTITY')) {
                $this->xml->reject();
            }
            $doc = new DOMDocument;
            if (! @$doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $doc->doctype !== null
                || $doc->documentElement?->localName !== $root || $doc->documentElement?->namespaceURI !== AuthorizeNetMutationXml::NS) {
                $this->xml->reject();
            }
            $xml = new DOMXPath($doc);
            $xml->registerNamespace('a', AuthorizeNetMutationXml::NS);

            return $xml;
        } catch (Throwable) {
            $this->xml->reject();
        } finally {
            try {
                $stream?->close();
            } catch (Throwable) {
                $this->xml->reject();
            }
        }
    }

    private function current(PaymentDispatchRequest $request, bool $claim): array
    {
        return DB::transaction(function () use ($request, $claim): array {
            $identity = PaymentDispatchAttempt::where('uuid', $request->attempt_uuid)->firstOrFail();
            $prep = PaymentDispatchPreparation::findOrFail($identity->payment_dispatch_preparation_id);
            app(PaymentAssociationLock::class)->acquire($prep->canonical_account_key, $prep->environment);
            $intentIdentity = PaymentIntent::findOrFail($identity->payment_intent_id);
            Order::withTrashed()->whereKey($intentIdentity->order_id)->lockForUpdate()->firstOrFail();
            $intent = PaymentIntent::whereKey($identity->payment_intent_id)->lockForUpdate()->firstOrFail();
            $operation = PaymentOperation::whereKey($identity->payment_operation_id)->lockForUpdate()->firstOrFail();
            $attempt = PaymentDispatchAttempt::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $binding = GatewayAccountBinding::findOrFail($prep->gateway_account_binding_id);
            $facts = $attempt->request_facts;
            if ($attempt->status !== 'claimed' || $attempt->transport_key !== $this->key()
                || $attempt->request_fingerprint !== $request->request_fingerprint || $this->ledger->fingerprint($facts) !== $request->request_fingerprint
                || $prep->uuid !== $request->preparation_uuid || $operation->uuid !== $request->operation_uuid
                || $operation->state !== PaymentOperationState::Prepared || $operation->amount_minor !== $request->amount_minor
                || $operation->purpose->value !== $request->purpose || $intent->currency !== $request->currency
                || $request->amount_minor < 1 || $request->amount_minor > 999999999999
                || ! in_array($request->currency, AuthorizeNetReportingClient::CURRENCIES, true)
                || ! preg_match('/\A[a-f0-9]{20}\z/', $request->merchant_reference)
                || ! in_array($request->environment, ['sandbox', 'production'], true)) {
                $this->xml->reject();
            }
            foreach (get_object_vars($request) as $name => $value) {
                if (! in_array($name, ['attempt_uuid', 'request_fingerprint'], true) && (! array_key_exists($name, $facts) || $facts[$name] !== $value)) {
                    $this->xml->reject();
                }
            }
            $linked = in_array($request->purpose, ['capture', 'refund', 'void'], true);
            if (($linked && ! preg_match('/\A[1-9][0-9]{0,31}\z/', $request->original_transaction_id ?? ''))
                || (! $linked && $request->original_transaction_id !== null)
                || PaymentOperation::where('payment_intent_id', $intent->id)->where('state', PaymentOperationState::Uncertain->value)->lockForUpdate()->exists()) {
                $this->xml->reject();
            }
            $this->scope->assertCurrent($operation, $binding);
            if ($linked) {
                $original = app(ResolvePaymentDispatchOriginal::class)->resolve($prep);
                if ($original->original_transaction_id !== $request->original_transaction_id
                    || $original->evidence_fingerprint !== ($facts['original_evidence_fingerprint'] ?? null)
                    || $original->parent_preparation_id !== ($facts['parent_preparation_id'] ?? null)) {
                    $this->xml->reject();
                }
            }
            $merchant = MerchantAccount::whereKey($binding->merchant_account_id)->lockForUpdate()->firstOrFail();
            if ($this->ledger->merchantFingerprint($merchant) !== $request->merchant_binding_fingerprint
                || $binding->canonical_account_key !== $request->canonical_account_key || $binding->id !== $request->gateway_account_binding_id) {
                $this->xml->reject();
            }
            if ($claim) {
                PaymentTransportInvocation::create(['payment_dispatch_attempt_id' => $attempt->id, 'claimed_at' => CarbonImmutable::now('UTC')]);
            } elseif (! PaymentTransportInvocation::where('payment_dispatch_attempt_id', $attempt->id)->exists()) {
                $this->xml->reject();
            }

            return [$merchant, $intent->customer_uuid];
        }, 3);
    }

    private function refundLastFour(PaymentDispatchRequest $request, MerchantAccount $merchant): string
    {
        $doc = $this->document($merchant, 'getTransactionDetailsRequest');
        $this->add($doc->documentElement, 'transId', $request->original_transaction_id);
        $xml = $this->exchange($doc->saveXML(), $request->environment, 'getTransactionDetailsResponse');
        $base = '/a:getTransactionDetailsResponse';
        $this->xml->element($xml, $base, 'messages');
        $this->xml->element($xml, $base, 'transaction');
        $txn = $base.'/a:transaction';
        if ($this->xml->value($xml, $base.'/a:messages', 'resultCode') !== 'Ok'
            || $this->xml->value($xml, $txn, 'transId') !== $request->original_transaction_id
            || $this->xml->value($xml, $txn, 'transactionStatus') !== 'settledSuccessfully'
            || ! in_array($this->xml->value($xml, $txn, 'transactionType'), ['authCaptureTransaction', 'authOnlyTransaction', 'priorAuthCaptureTransaction'], true)) {
            $this->xml->reject();
        }
        $amount = $this->xml->value($xml, $txn, 'settleAmount');
        if (! preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', $amount, $parts)
            || ((int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0')) < $request->amount_minor) {
            $this->xml->reject();
        }
        $payment = $this->xml->element($xml, $txn, 'payment');
        if ($xml->query($txn.'/a:payment/*')->length !== 1) {
            $this->xml->reject();
        }
        $this->xml->element($xml, $txn.'/a:payment', 'creditCard');
        $masked = $this->xml->value($xml, $txn.'/a:payment/a:creditCard', 'cardNumber');
        if (! preg_match('/\AXXXX([0-9]{4})\z/', $masked, $parts)) {
            $this->xml->reject();
        }

        return $parts[1];
    }

    private function document(#[\SensitiveParameter] MerchantAccount $merchant, string $root): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->appendChild($doc->createElementNS(AuthorizeNetMutationXml::NS, $root));
        $auth = $this->add($doc->documentElement, 'merchantAuthentication');
        $this->add($auth, 'name', $merchant->authnet_api_login_id);
        $this->add($auth, 'transactionKey', $merchant->authnet_transaction_key);

        return $doc;
    }

    private function add(DOMElement $parent, string $name, #[\SensitiveParameter] ?string $value = null): DOMElement
    {
        $node = $parent->appendChild($parent->ownerDocument->createElement($name));
        if ($value !== null) {
            $node->appendChild($parent->ownerDocument->createTextNode($value));
        }

        return $node;
    }
}
