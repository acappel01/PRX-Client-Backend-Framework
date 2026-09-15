<?php

namespace App\Services\Payments;

use App\Data\Payments\AuthorizeNetMerchantRead;
use App\Data\Payments\AuthorizeNetTransactionRead;
use App\Data\Payments\GatewayTransactionReadData;
use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Models\Payments\MerchantAccount;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Read-only XML transport: preserve exact decimal text and discard response PII. */
class AuthorizeNetReportingClient
{
    public const NS = 'AnetApi/xml/v1/schema/AnetApiSchema.xsd';

    public const MAX_BYTES = 262144;

    public const CURRENCIES = ['USD', 'CAD', 'GBP', 'DKK', 'NOK', 'PLN', 'SEK', 'EUR', 'AUD', 'NZD'];

    public function assertUsable(MerchantAccount $merchant): void
    {
        if (! $merchant->exists || ! $merchant->is_active || $merchant->gateway_provider !== GatewayProvider::AuthorizeNet
            || filled($merchant->gateway_endpoint_url) || ! $merchant->hasValidCredentials()) {
            $this->reject();
        }
    }

    public function merchant(MerchantAccount $merchant): AuthorizeNetMerchantRead
    {
        $xml = $this->request($merchant, 'getMerchantDetailsRequest', 'getMerchantDetailsResponse');
        $id = $this->value($xml, '/a:getMerchantDetailsResponse/a:gatewayId');
        $currency = $this->value($xml, '/a:getMerchantDetailsResponse/a:currencies/a:currency');
        // Test mode is independent of the sandbox/production endpoint and cannot prove money moved.
        if ($this->value($xml, '/a:getMerchantDetailsResponse/a:isTestMode') !== 'false'
            || ! preg_match('/\A[1-9][0-9]{0,31}\z/', $id) || ! in_array($currency, self::CURRENCIES, true)) {
            $this->reject();
        }

        return new AuthorizeNetMerchantRead($id, $currency, $this->processors($xml));
    }

    /** Only authenticated processor identity; no merchant address or instrument data retained. */
    private function processors(DOMXPath $xml): array
    {
        $base = '/a:getMerchantDetailsResponse';
        $containers = $xml->query($base.'/*[local-name()="processors"]');
        if ($containers->length === 0) {
            return [];
        }
        if ($containers->length !== 1 || $containers->item(0)->namespaceURI !== self::NS || $containers->item(0)->hasAttributes()) {
            $this->reject();
        }
        $nodes = $xml->query($base.'/a:processors/*');
        if ($nodes->length > 16) {
            $this->reject();
        }
        $result = [];
        $ids = [];
        foreach ($nodes as $index => $node) {
            if ($node->namespaceURI !== self::NS || $node->localName !== 'processor' || $node->hasAttributes()) {
                $this->reject();
            }
            $path = $base.'/a:processors/a:processor['.($index + 1).']';
            foreach (['name', 'id'] as $field) {
                $fields = $xml->query($path.'/*[local-name()="'.$field.'"]');
                if ($fields->length !== 1 || $fields->item(0)->namespaceURI !== self::NS || $fields->item(0)->hasAttributes()) {
                    $this->reject();
                }
            }
            $name = $this->value($xml, $path.'/a:name');
            $id = $this->value($xml, $path.'/a:id');
            if (! preg_match('/\A[\x20-\x7e]{1,255}\z/', $name) || trim($name) !== $name
                || ! preg_match('/\A[1-9][0-9]{0,9}\z/', $id) || (int) $id > 2147483647 || isset($ids[$id])) {
                $this->reject();
            }
            $ids[$id] = true;
            $result[] = ['id' => $id, 'name' => $name];
        }

        return $result;
    }

    public function transaction(MerchantAccount $merchant, GatewayTransactionReadData $data, string $canonicalAccountKey): AuthorizeNetTransactionRead
    {
        if (! preg_match('/\A[1-9][0-9]{0,31}\z/', $data->transaction_id)
            || ($data->expected_merchant_reference !== null && ! $this->validMerchantReference($data->expected_merchant_reference))) {
            $this->reject();
        }
        $xml = $this->request($merchant, 'getTransactionDetailsRequest', 'getTransactionDetailsResponse', $data->transaction_id);
        $merchantReference = $this->merchantReference($xml);
        if ($data->expected_merchant_reference !== null && $merchantReference !== $data->expected_merchant_reference) {
            $this->reject();
        }
        $base = '/a:getTransactionDetailsResponse/a:transaction/a:';
        $value = fn (string $field, bool $optional = false) => $this->value($xml, $base.$field, $optional);
        $id = $value('transId');
        $type = $value('transactionType');
        $status = $value('transactionStatus');
        $original = $value('refTransId', true);
        $auth = $this->minor($value('authAmount'));
        $settle = $this->minor($value('settleAmount'));
        $allowed = match ($type) {
            'authOnlyTransaction' => ['authorizedPendingCapture', 'capturedPendingSettlement', 'settledSuccessfully', 'settlementError', 'expired', 'voided', 'declined', 'couldNotVoid', 'generalError', 'underReview', 'FDSPendingReview', 'FDSAuthorizedPendingReview', 'failedReview'],
            'authCaptureTransaction', 'priorAuthCaptureTransaction' => ['capturedPendingSettlement', 'settledSuccessfully', 'voided', 'declined', 'couldNotVoid', 'settlementError', 'generalError', 'underReview', 'FDSPendingReview', 'FDSAuthorizedPendingReview', 'failedReview'],
            'refundTransaction' => ['refundPendingSettlement', 'refundSettledSuccessfully', 'voided', 'declined', 'settlementError', 'generalError'],
            default => [],
        };
        if ($id !== $data->transaction_id || $type !== $data->expected_transaction_type
            || $original !== $data->expected_original_transaction_id
            || ($data->capture_financial_observation ? ! preg_match('/\A[A-Za-z][A-Za-z0-9]{0,63}\z/', $status) : ! in_array($status, $allowed, true))
            || (! $data->capture_financial_observation && (($data->expected_amount_basis ?? ($type === 'authOnlyTransaction' ? 'authorization' : 'settlement')) === 'authorization' ? $auth : $settle) !== $data->expected_amount_minor)) {
            $this->reject();
        }

        return new AuthorizeNetTransactionRead($canonicalAccountKey, $id, $type, $status, $original, $auth, $settle, $data->expected_currency, CarbonImmutable::now(), merchant_reference: $merchantReference,
            submitted_at: $this->submissionTime($xml), payment_rail: $this->paymentRail($xml));
    }

    /** Strict optional UTC reporting time; unsupported precision refuses instead of rounding. */
    private function submissionTime(DOMXPath $xml): ?CarbonImmutable
    {
        $base = '/a:getTransactionDetailsResponse/a:transaction';
        $nodes = $xml->query($base.'/*[local-name()="submitTimeUTC"]');
        if ($nodes->length === 0) {
            return null;
        }
        if ($nodes->length !== 1 || $nodes->item(0)->namespaceURI !== self::NS || $nodes->item(0)->hasAttributes()) {
            $this->reject();
        }
        $text = $this->value($xml, $base.'/a:submitTimeUTC');
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,6}))?Z\z/', $text, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            $this->reject();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', substr($text, 0, 19).'.'.str_pad($m[7] ?? '', 6, '0').'Z', 'UTC');
    }

    /** Classification only; no instrument values cross the XML boundary. */
    private function paymentRail(DOMXPath $xml): string
    {
        $base = '/a:getTransactionDetailsResponse/a:transaction';
        $nodes = $xml->query($base.'/*[local-name()="payment"]');
        if ($nodes->length === 0) {
            return 'unknown';
        }
        if ($nodes->length !== 1 || $nodes->item(0)->namespaceURI !== self::NS || $nodes->item(0)->hasAttributes()) {
            $this->reject();
        }
        $children = $xml->query($base.'/a:payment/*');
        if ($children->length !== 1 || $children->item(0)->namespaceURI !== self::NS || $children->item(0)->hasAttributes()) {
            $this->reject();
        }

        return match ($children->item(0)->localName) {
            'creditCard' => 'credit_card', 'bankAccount' => 'bank_account', 'tokenInformation' => 'token', default => 'unknown',
        };
    }

    /** Original merchant refId echoed by reporting, distinct from read refId and transaction refTransId. */
    private function merchantReference(DOMXPath $xml): ?string
    {
        $nodes = $xml->query('/a:getTransactionDetailsResponse/*[local-name()="transrefId"]');
        if ($nodes->length === 0) {
            return null;
        }
        if ($nodes->length !== 1 || $nodes->item(0)->namespaceURI !== self::NS
            || $nodes->item(0)->hasAttributes()) {
            $this->reject();
        }
        $reference = $this->value($xml, '/a:getTransactionDetailsResponse/a:transrefId');
        if (! $this->validMerchantReference($reference)) {
            $this->reject();
        }

        return $reference;
    }

    private function validMerchantReference(string $reference): bool
    {
        return preg_match('/\A[A-Za-z0-9._:-]{1,20}\z/', $reference) === 1;
    }

    private function value(DOMXPath $xml, string $path, bool $optional = false): ?string
    {
        $nodes = $xml->query($path);
        if ($optional && $nodes->length === 0) {
            return null;
        }
        if ($nodes->length !== 1 || $nodes->item(0)->childNodes->length !== 1
            || $nodes->item(0)->firstChild->nodeType !== XML_TEXT_NODE) {
            $this->reject();
        }

        return $nodes->item(0)->textContent;
    }

    public function minor(string $decimal): int
    {
        // All explicitly supported currencies have two decimal minor units.
        if (! preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', $decimal, $parts)) {
            $this->reject();
        }

        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private function request(MerchantAccount $merchant, string $request, string $response, ?string $transactionId = null): DOMXPath
    {
        $this->assertUsable($merchant);
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(self::NS, $request);
        $document->appendChild($root);
        $auth = $root->appendChild($document->createElement('merchantAuthentication'));
        foreach (['name' => $merchant->authnet_api_login_id, 'transactionKey' => $merchant->authnet_transaction_key] as $name => $value) {
            $node = $auth->appendChild($document->createElement($name));
            $node->appendChild($document->createTextNode($value));
        }
        if ($transactionId !== null) {
            $root->appendChild($document->createElement('transId', $transactionId));
        }
        $endpoint = $merchant->environment === GatewayEnvironment::Sandbox
            ? 'https://apitest.authorize.net/xml/v1/request.api' : 'https://api.authorize.net/xml/v1/request.api';
        try {
            $stream = null;
            $http = Http::withBody($document->saveXML(), 'application/xml')->accept('application/xml')
                ->withHeaders(['Accept-Encoding' => 'identity'])->connectTimeout(5)->timeout(15)->withoutRedirecting()->withOptions([
                    'stream' => true, 'decode_content' => false, 'read_timeout' => 5,
                    'on_headers' => function ($response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            $this->reject();
                        }
                    },
                ])->post($endpoint);
            $stream = $http->toPsrResponse()->getBody();
            if ($http->status() !== 200 || filled($http->header('Content-Encoding'))) {
                $this->reject();
            }
            $body = '';
            $deadline = hrtime(true) + 15_000_000_000;
            while (! $stream->eof() && strlen($body) <= self::MAX_BYTES) {
                if (hrtime(true) > $deadline) {
                    $this->reject();
                }
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
                if ($chunk === '' && ! $stream->eof()) {
                    $this->reject();
                }
                $body .= $chunk;
            }
            $stream->close();
            if (strlen($body) > self::MAX_BYTES || str_contains(strtoupper($body), '<!DOCTYPE') || str_contains(strtoupper($body), '<!ENTITY')) {
                $this->reject();
            }
            $xml = new DOMDocument;
            if (! @$xml->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                || $xml->doctype !== null || $xml->documentElement?->localName !== $response
                || $xml->documentElement?->namespaceURI !== self::NS) {
                $this->reject();
            }
            $xpath = new DOMXPath($xml);
            $xpath->registerNamespace('a', self::NS);
            $container = $response === 'getMerchantDetailsResponse' ? 'currencies' : 'transaction';
            if ($xpath->query('/a:'.$response.'/a:messages')->length !== 1
                || $xpath->query('/a:'.$response.'/a:'.$container)->length !== 1
                || $this->value($xpath, '/a:'.$response.'/a:messages/a:resultCode') !== 'Ok') {
                $this->reject();
            }

            return $xpath;
        } catch (Throwable) {
            // Never chain provider exceptions: requests contain credentials, responses may contain PII.
            $this->reject();
        } finally {
            $stream?->close();
        }
    }

    public function reject(): never
    {
        throw ValidationException::withMessages(['gateway_read' => 'Gateway account or reporting evidence could not be verified.']);
    }
}
