<?php

namespace App\Services\Payments;

use App\Data\Payments\AuthorizeNetTransactionRead;
use App\Data\Payments\GatewayTransactionReadData;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\MerchantAccount;
use Illuminate\Support\Facades\DB;

/** Internal current reporting read; no operation ownership claim or uncertainty resolution. */
class ReadAuthorizeNetTransaction
{
    public function __construct(private readonly AuthorizeNetReportingClient $client, private readonly PaymentLedgerScope $scope) {}

    public function execute(GatewayTransactionReadData $data): AuthorizeNetTransactionRead
    {
        if (DB::transactionLevel() !== 0) {
            $this->client->reject();
        }
        $binding = GatewayAccountBinding::find($data->binding_id);
        if ($binding === null || $data->expected_currency !== $binding->currency
            || ! in_array($data->expected_amount_basis, [null, 'authorization', 'settlement'], true)
            || $data->expected_amount_minor < 1 || $data->expected_amount_minor > 999999999999
            || ! in_array($data->expected_transaction_type, ['authOnlyTransaction', 'authCaptureTransaction', 'priorAuthCaptureTransaction', 'refundTransaction'], true)
            || ($data->expected_original_transaction_id !== null && ! preg_match('/\A[1-9][0-9]{0,31}\z/', $data->expected_original_transaction_id))
            || ($data->expected_transaction_type === 'refundTransaction' && $data->expected_original_transaction_id === null)
            || (in_array($data->expected_transaction_type, ['authOnlyTransaction', 'authCaptureTransaction'], true) && $data->expected_original_transaction_id !== null)) {
            $this->client->reject();
        }
        $merchant = $this->current($binding);
        $account = $this->client->merchant($merchant);
        if ($account->gateway_account_id !== $binding->gateway_account_id || $account->currency !== $binding->currency) {
            $this->client->reject();
        }
        $read = $this->client->transaction($merchant, $data, $binding->canonical_account_key);
        $read->account_processors = $account->processors;
        // DB credentials/config may change while either request is in flight.
        $this->current($binding);

        return $read;
    }

    public function current(GatewayAccountBinding $binding): MerchantAccount
    {
        $merchant = MerchantAccount::find($binding->merchant_account_id);
        if ($merchant === null || $merchant->uuid !== $binding->merchant_account_uuid
            || ! hash_equals($binding->merchant_fingerprint, $this->scope->merchantFingerprint($merchant))) {
            $this->client->reject();
        }
        $this->client->assertUsable($merchant);

        return $merchant;
    }
}
