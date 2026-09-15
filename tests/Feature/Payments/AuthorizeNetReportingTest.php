<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\VerifyGatewayAccountBindingAction;
use App\Data\Payments\GatewayAccountBindingData;
use App\Data\Payments\GatewayTransactionReadData;
use App\Enums\Payments\GatewayEnvironment;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use App\Services\Payments\AuthorizeNetCurrencyAuthority;
use App\Services\Payments\AuthorizeNetReportingClient;
use App\Services\Payments\ReadAuthorizeNetTransaction;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AuthorizeNetReportingTest extends TestCase
{
    private MerchantAccount $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        // Reporting explicitly refuses caller transactions; use a fresh synthetic schema.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        $this->merchant = MerchantAccount::factory()->create(['authnet_signature_key' => str_repeat('ab', 64)]);
    }

    private function freshHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function xml(string $root, string $body): string
    {
        return '<'.$root.' xmlns="'.AuthorizeNetReportingClient::NS.'"><messages><resultCode>Ok</resultCode></messages>'.$body.'</'.$root.'>';
    }

    private function merchantXml(string $id = '565697', string $currencies = '<currency>USD</currency>'): string
    {
        return $this->xml('getMerchantDetailsResponse', '<isTestMode>false</isTestMode><gatewayId>'.$id.'</gatewayId><currencies>'.$currencies.'</currencies><merchantName>Synthetic private merchant</merchantName>');
    }

    private function transactionXml(array $changes = []): string
    {
        $fields = $changes + ['transId' => '6000001', 'transactionType' => 'authCaptureTransaction', 'transactionStatus' => 'settledSuccessfully', 'authAmount' => '25.01', 'settleAmount' => '25.01'];
        $body = '';
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $body .= '<'.$key.'>'.$value.'</'.$key.'>';
            }
        }

        return $this->xml('getTransactionDetailsResponse', '<transaction>'.$body.'<customer><email>synthetic-private@example.test</email></customer><payment><creditCard><cardNumber>XXXX1234</cardNumber></creditCard></payment></transaction>');
    }

    private function bind(?MerchantAccount $merchant = null, string $id = '565697'): GatewayAccountBinding
    {
        $merchant ??= $this->merchant;

        return app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($merchant->id, $merchant->environment, $id, 'USD', $merchant->provider_merchant_profile_id));
    }

    private function read(GatewayAccountBinding $binding, array $changes = []): mixed
    {
        return app(ReadAuthorizeNetTransaction::class)->execute(new GatewayTransactionReadData(...($changes + [
            'binding_id' => $binding->id, 'transaction_id' => '6000001', 'expected_transaction_type' => 'authCaptureTransaction',
            'expected_original_transaction_id' => null, 'expected_amount_minor' => 2501, 'expected_currency' => 'USD',
        ])));
    }

    private function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected closed gateway verification.');
        } catch (ValidationException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('synthetic-private', $exception->getMessage());
        }
    }

    public function test_original_merchant_reference_is_exposed_and_exactly_matched_without_conflating_other_references(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $binding = $this->bind();
        $reference = '0123456789abcdef0123';
        $body = str_replace('</getTransactionDetailsResponse>', '<refId>report-request</refId><transrefId>'.$reference.'</transrefId></getTransactionDetailsResponse>', $this->transactionXml());
        $this->freshHttp();
        Http::fakeSequence()->push($this->merchantXml())->push($body);
        $read = $this->read($binding, ['expected_merchant_reference' => $reference]);
        $this->assertSame($reference, $read->merchant_reference);
        $this->assertNull($read->original_transaction_id);
        $this->assertFalse($read->transaction_currency_verified);
        $this->assertSame('current_merchant_configuration', $read->currency_authority);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'getTransactionDetailsRequest')
            && ! str_contains($request->body(), '<refId>') && ! str_contains($request->body(), $reference));

        $this->freshHttp();
        Http::fakeSequence()->push($this->merchantXml())->push($body);
        $this->assertSame($reference, $this->read($binding)->merchant_reference);
    }

    public function test_absent_reference_remains_null_and_read_request_or_original_transaction_reference_cannot_substitute(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $binding = $this->bind();
        $body = str_replace('</getTransactionDetailsResponse>', '<refId>6000000</refId></getTransactionDetailsResponse>',
            $this->transactionXml(['transactionType' => 'refundTransaction', 'transactionStatus' => 'refundSettledSuccessfully', 'refTransId' => '6000000', 'transrefId' => 'nested-ignored']));
        $changes = ['expected_transaction_type' => 'refundTransaction', 'expected_original_transaction_id' => '6000000'];
        $this->freshHttp();
        Http::fakeSequence()->push($this->merchantXml())->push($body);
        $read = $this->read($binding, $changes);
        $this->assertNull($read->merchant_reference);
        $this->assertSame('6000000', $read->original_transaction_id);
        $this->freshHttp();
        Http::fakeSequence()->push($this->merchantXml())->push($body);
        $this->invalid(fn () => $this->read($binding, $changes + ['expected_merchant_reference' => '6000000']));
    }

    public function test_conflicting_malformed_duplicate_or_foreign_namespace_merchant_references_fail_closed(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $binding = $this->bind();
        foreach ([
            '<transrefId/>',
            '<transrefId>0123456789abcdef01234</transrefId>',
            '<transrefId> padded</transrefId>',
            '<transrefId>non ascii é</transrefId>',
            '<transrefId>reference&#10;</transrefId>',
            '<transrefId><value>reference</value></transrefId>',
            '<transrefId><![CDATA[reference]]></transrefId>',
            '<transrefId attribute="value">reference</transrefId>',
            '<transrefId>reference</transrefId><transrefId>reference</transrefId>',
            '<transrefId xmlns="urn:untrusted">reference</transrefId>',
            '<transrefId>reference</transrefId><transrefId xmlns="urn:untrusted">reference</transrefId>',
        ] as $field) {
            $body = str_replace('</getTransactionDetailsResponse>', $field.'</getTransactionDetailsResponse>', $this->transactionXml());
            $this->freshHttp();
            Http::fakeSequence()->push($this->merchantXml())->push($body);
            $this->invalid(fn () => $this->read($binding));
        }
        $body = str_replace('</getTransactionDetailsResponse>', '<transrefId>Reference</transrefId></getTransactionDetailsResponse>', $this->transactionXml());
        $this->freshHttp();
        Http::fakeSequence()->push($this->merchantXml())->push($body);
        $this->invalid(fn () => $this->read($binding, ['expected_merchant_reference' => 'reference']));
    }

    public function test_invalid_expected_merchant_reference_is_refused_before_transaction_http(): void
    {
        $this->freshHttp();
        Http::fake();
        foreach (['', 'contains space', str_repeat('a', 21), "reference\n", '<reference>'] as $reference) {
            $data = new GatewayTransactionReadData(1, '6000001', 'authCaptureTransaction', null, 2501, 'USD', $reference);
            $this->invalid(fn () => app(AuthorizeNetReportingClient::class)->transaction($this->merchant, $data, 'synthetic-account'));
        }
        Http::assertNothingSent();
    }

    public function test_mapping_requires_real_read_and_explicit_identity_and_replays_immutably(): void
    {
        $this->merchant->update(['provider_merchant_profile_id' => 'synthetic-prx-row']);
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $this->invalid(fn () => $this->bind(id: '999999'));
        $this->assertDatabaseCount('gateway_account_bindings', 0);
        $binding = $this->bind();
        $this->assertSame($binding->id, $this->bind()->id);
        $this->assertSame('synthetic-prx-row', $binding->provider_mapping);
        $this->assertStringNotContainsString('synthetic-prx-row', DB::table('gateway_account_bindings')->value('provider_mapping'));
        $this->assertArrayNotHasKey('merchant_fingerprint', $binding->toArray());
        $this->assertArrayNotHasKey('gateway_account_id', $binding->toArray());
        $this->expectException(LogicException::class);
        $binding->update(['currency' => 'CAD']);
    }

    public function test_duplicate_local_rows_share_only_authenticated_remote_account_and_environment(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $first = $this->bind();
        $duplicate = MerchantAccount::factory()->create(['authnet_signature_key' => str_repeat('cd', 64)]);
        $second = $this->bind($duplicate);
        $this->assertSame($first->canonical_account_key, $second->canonical_account_key);
        $this->assertNotSame($first->merchant_fingerprint, $second->merchant_fingerprint);
        $production = MerchantAccount::factory()->production()->create(['authnet_signature_key' => str_repeat('ef', 64)]);
        $this->assertNotSame($first->canonical_account_key, $this->bind($production)->canonical_account_key);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.authorize.net/xml/v1/request.api');
    }

    public function test_current_read_returns_exact_allowlisted_facts_without_projecting_financial_state(): void
    {
        $this->freshHttp();
        Http::fakeSequence()->push($this->merchantXml())->push($this->merchantXml())->push($this->transactionXml());
        $read = $this->read($this->bind());
        $this->assertSame(2501, $read->settlement_amount_minor);
        $this->assertFalse($read->transaction_currency_verified);
        $this->assertSame('current_merchant_configuration', $read->currency_authority);
        $this->assertSame('settledSuccessfully', $read->transaction_status);
        $this->assertStringNotContainsString('synthetic-private', $read->toJson());
        $this->assertStringNotContainsString('XXXX1234', $read->toJson());
        $this->assertDatabaseCount('payment_outcome_observations', 0);
        $this->assertDatabaseCount('payment_operations', 0);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->body(), '<getTransactionDetailsRequest') && str_contains($request->body(), '<transId>6000001</transId>'));
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('createTransactionRequest', $request->body());
        }
    }

    public function test_merchant_configuration_drift_blocks_reads_and_rebinding(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $binding = $this->bind();
        $this->merchant->update(['authnet_transaction_key' => 'rotated-synthetic-key']);
        $this->invalid(fn () => $this->read($binding));
        Http::assertSentCount(1);
        $this->invalid(fn () => $this->bind());
        $this->assertDatabaseCount('gateway_account_bindings', 1);
    }

    public function test_disabled_missing_credentials_endpoint_override_and_wrong_mapping_refuse_before_http(): void
    {
        $this->freshHttp();
        Http::fake();
        foreach ([['is_active' => false], ['authnet_transaction_key' => null], ['gateway_endpoint_url' => 'https://untrusted.example.test']] as $change) {
            $merchant = MerchantAccount::factory()->create($change + ['authnet_signature_key' => str_repeat('ab', 64)]);
            $this->invalid(fn () => $this->bind($merchant));
        }
        $this->invalid(fn () => app(VerifyGatewayAccountBindingAction::class)->execute(new GatewayAccountBindingData($this->merchant->id, GatewayEnvironment::Sandbox, '565697', 'USD', 'wrong-provider-row')));
        Http::assertNothingSent();
    }

    public function test_ambiguous_currency_test_mode_missing_identity_and_duplicate_fields_are_rejected(): void
    {
        $responses = [
            $this->merchantXml(currencies: '<currency>USD</currency><currency>CAD</currency>'),
            $this->merchantXml(currencies: '<currency>JPY</currency>'),
            str_replace('<isTestMode>false</isTestMode>', '<isTestMode>true</isTestMode>', $this->merchantXml()),
            str_replace('<gatewayId>565697</gatewayId>', '', $this->merchantXml()),
            str_replace('<gatewayId>565697</gatewayId>', '<gatewayId>565697</gatewayId><gatewayId>565697</gatewayId>', $this->merchantXml()),
            str_replace(AuthorizeNetReportingClient::NS, 'untrusted', $this->merchantXml()),
            str_replace('</currencies>', '</currencies><currencies/>', $this->merchantXml()),
            '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$this->merchantXml(),
        ];
        foreach ($responses as $response) {
            $this->freshHttp();
            Http::fake(['*' => Http::response($response)]);
            $this->invalid(fn () => $this->bind());
        }
        $this->assertDatabaseCount('gateway_account_bindings', 0);
    }

    public function test_transport_errors_redirect_compression_oversize_and_invalid_xml_fail_closed(): void
    {
        foreach ([Http::response('', 302, ['Location' => 'https://untrusted.example.test']), Http::response($this->merchantXml(), 200, ['Content-Encoding' => 'gzip']), Http::response(str_repeat('x', AuthorizeNetReportingClient::MAX_BYTES + 1)), Http::response('<broken'), Http::response($this->merchantXml(), 500)] as $response) {
            $this->freshHttp();
            Http::fake(['*' => $response]);
            $this->invalid(fn () => $this->bind());
        }
        $this->freshHttp();
        Http::fake(fn () => throw new ConnectionException('synthetic-private credentials and response'));
        $this->invalid(fn () => $this->bind());
        $this->assertDatabaseCount('gateway_account_bindings', 0);
    }

    public function test_remote_identity_currency_transaction_original_type_status_and_amount_must_match(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $binding = $this->bind();
        foreach ([['transId' => '6000002'], ['transactionType' => 'refundTransaction'], ['transactionStatus' => 'unknownFutureStatus'], ['refTransId' => '100'], ['settleAmount' => '25.02'], ['settleAmount' => '2.501e1'], ['settleAmount' => '25.010'], ['authAmount' => '-1.00'], ['transId' => '6000001</transId><transId>6000001']] as $changes) {
            $this->freshHttp();
            Http::fakeSequence()->push($this->merchantXml())->push($this->transactionXml($changes));
            $this->invalid(fn () => $this->read($binding));
        }
        $this->freshHttp();
        Http::fake(['*' => Http::response($this->merchantXml('565698'))]);
        $this->invalid(fn () => $this->read($binding));
        $this->freshHttp();
        Http::fake(['*' => Http::response($this->merchantXml(currencies: '<currency>CAD</currency>'))]);
        $this->invalid(fn () => $this->read($binding));
    }

    public function test_authorization_capture_refund_and_void_remain_distinct_with_explicit_lineage(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $binding = $this->bind();
        foreach ([['authOnlyTransaction', 'authorizedPendingCapture', null, '0.00'], ['authCaptureTransaction', 'capturedPendingSettlement', null, '25.01'], ['authCaptureTransaction', 'voided', null, '25.01'], ['refundTransaction', 'refundPendingSettlement', '6000000', '25.01'], ['refundTransaction', 'refundSettledSuccessfully', '6000000', '25.01']] as [$type, $status, $original, $settle]) {
            $this->freshHttp();
            Http::fakeSequence()->push($this->merchantXml())->push($this->transactionXml(['transactionType' => $type, 'transactionStatus' => $status, 'refTransId' => $original, 'settleAmount' => $settle]));
            $read = $this->read($binding, ['expected_transaction_type' => $type, 'expected_original_transaction_id' => $original]);
            $this->assertSame($status, $read->transaction_status);
            $this->assertSame($original, $read->original_transaction_id);
        }
    }

    public function test_config_change_during_remote_read_never_returns_stale_binding(): void
    {
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $binding = $this->bind();
        $this->freshHttp();
        Http::fake(function ($request) {
            if (str_contains($request->body(), 'getTransactionDetailsRequest')) {
                $this->merchant->update(['provider_merchant_profile_id' => 'changed-during-read']);

                return Http::response($this->transactionXml());
            }

            return Http::response($this->merchantXml());
        });
        $this->invalid(fn () => $this->read($binding));
    }

    public function test_caller_transactions_are_rejected_before_network(): void
    {
        $this->freshHttp();
        Http::fake();
        DB::transaction(function (): void {
            $this->invalid(fn () => $this->bind());
            $this->invalid(fn () => app(ReadAuthorizeNetTransaction::class)->execute(new GatewayTransactionReadData(1, '6000001', 'authCaptureTransaction', null, 2501, 'USD')));
        });
        Http::assertNothingSent();
    }

    public function test_signature_key_is_not_required_for_authenticated_reporting(): void
    {
        $this->merchant->update(['authnet_signature_key' => null]);
        $this->freshHttp();
        Http::fake(fn () => Http::response($this->merchantXml()));
        $this->assertSame('565697', $this->bind()->gateway_account_id);
    }

    public function test_decimal_conversion_is_exact_and_bounded_without_rounding(): void
    {
        $client = app(AuthorizeNetReportingClient::class);
        foreach (['0' => 0, '0.1' => 10, '25.01' => 2501, '9999999999.99' => 999999999999] as $value => $expected) {
            $this->assertSame($expected, $client->minor((string) $value));
        }
        foreach (['1e2', '+1', '-1', '01.00', '1.001', '10000000000', ' 1', 'NaN'] as $value) {
            $this->invalid(fn () => $client->minor($value));
        }
    }

    public function test_submission_time_and_payment_rail_are_allowlisted_without_instrument_details(): void
    {
        Http::fakeSequence()->push($this->merchantXml())->push($this->merchantXml())
            ->push($this->transactionXml(['submitTimeUTC' => '2026-09-15T01:02:03.123456Z']));
        $binding = $this->bind();
        $read = $this->read($binding);
        $this->assertSame('2026-09-15T01:02:03.123456Z', $read->submitted_at->format('Y-m-d\\TH:i:s.u\\Z'));
        $this->assertSame('credit_card', $read->payment_rail);
        $this->assertStringNotContainsString('XXXX1234', json_encode($read->toArray()));
        $this->assertFalse($read->transaction_currency_verified);
    }

    public function test_reporting_time_rejects_normalized_invalid_ambiguous_and_excess_precision_values(): void
    {
        foreach (['2026-02-30T00:00:00Z', '2026-09-15T25:00:00Z', '2026-09-15T00:00:00.1234567Z',
            '2026-09-15T00:00:00+01:00', '2026-09-15', '2026-09-15T00:00:00Z</submitTimeUTC><submitTimeUTC>2026-09-15T00:00:00Z',
            '</submitTimeUTC><x:submitTimeUTC xmlns:x="urn:foreign">2026-09-15T00:00:00Z</x:submitTimeUTC><submitTimeUTC>2026-09-15T00:00:00Z'] as $date) {
            $this->freshHttp();
            Http::fakeSequence()->push($this->merchantXml())->push($this->merchantXml())
                ->push($this->transactionXml(['submitTimeUTC' => $date]));
            $binding = $this->bind();
            $this->invalid(fn () => $this->read($binding));
        }
    }

    public function test_missing_time_and_unsupported_rails_do_not_gain_currency_authority(): void
    {
        foreach (['<bankAccount><accountNumber>XXXX1234</accountNumber></bankAccount>' => 'bank_account',
            '<tokenInformation><tokenNumber>synthetic</tokenNumber></tokenInformation>' => 'token',
            '<payPal/>' => 'unknown'] as $payment => $rail) {
            $this->freshHttp();
            $body = str_replace('<creditCard><cardNumber>XXXX1234</cardNumber></creditCard>', $payment, $this->transactionXml());
            Http::fakeSequence()->push($this->merchantXml())->push($this->merchantXml())->push($body);
            $binding = $this->bind();
            $read = $this->read($binding);
            $this->assertNull($read->submitted_at);
            $this->assertSame($rail, $read->payment_rail);
            $this->assertFalse(app(AuthorizeNetCurrencyAuthority::class)->assess($binding, $read)['currency_qualified']);
        }
    }

    public function test_ambiguous_or_foreign_payment_containers_are_not_credit_card_authority(): void
    {
        foreach (['<creditCard/><bankAccount/>', '<x:creditCard xmlns:x="urn:foreign"/>', '<creditCard/><creditCard/>'] as $payment) {
            $this->freshHttp();
            $body = str_replace('<creditCard><cardNumber>XXXX1234</cardNumber></creditCard>', $payment, $this->transactionXml());
            Http::fakeSequence()->push($this->merchantXml())->push($this->merchantXml())->push($body);
            $binding = $this->bind();
            $this->invalid(fn () => $this->read($binding));
        }
    }

    public function test_currency_policy_is_explicit_sandbox_inference_and_never_a_production_or_observed_currency_claim(): void
    {
        Http::fakeSequence()->push($this->merchantXml())->push($this->merchantXml())->push($this->transactionXml());
        $binding = $this->bind();
        $read = $this->read($binding);
        $policy = app(AuthorizeNetCurrencyAuthority::class);
        $result = $policy->assess($binding, $read);
        $this->assertTrue($result['currency_qualified']);
        $this->assertFalse($result['transaction_currency_observed']);
        $this->assertSame('authorize_net_fixed_sandbox_currency_v1', $result['authority']);
        $this->assertFalse($read->transaction_currency_verified);
        $production = clone $binding;
        $production->environment = 'production';
        $this->assertSame('production_policy_scope_unqualified', $policy->assess($production, $read)['reason']);
        $wrong = clone $binding;
        $wrong->canonical_account_key = str_repeat('a', 64);
        $this->assertSame('account_scope_mismatch', $policy->assess($wrong, $read)['reason']);
        $wrong->canonical_account_key = $binding->canonical_account_key;
        $wrong->currency = 'CAD';
        $this->assertFalse($policy->assess($wrong, $read)['currency_qualified']);
    }
}
