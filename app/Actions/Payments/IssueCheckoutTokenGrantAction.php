<?php

namespace App\Actions\Payments;

use App\Data\Payments\CheckoutTokenGrantData;
use App\Models\Payments\CheckoutTokenGrant;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Services\Payments\CheckoutTokenScope;
use App\Services\Payments\PaymentLedgerScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Internal only; no checkout mutation, default binding or anonymous ownership assumption. */
final class IssueCheckoutTokenGrantAction
{
    public function __construct(private readonly CheckoutTokenScope $scope, private readonly PaymentLedgerScope $ledger) {}

    public function execute(CheckoutTokenGrantData $data, #[\SensitiveParameter] string $bearer, #[\SensitiveParameter] string $opaqueToken): CheckoutTokenGrant
    {
        try {
            if (DB::transactionLevel() !== 0 || config('payments.checkout_token_authorization_enabled', false) !== true
                || ! Str::isUuid($data->uuid) || ! Str::isUuid($data->preparation_uuid)) {
                $this->scope->reject();
            }
            $tokenFingerprint = $this->scope->tokenFingerprint($opaqueToken);

            return DB::transaction(function () use ($data, $bearer, $tokenFingerprint): CheckoutTokenGrant {
                [$prep,$facts] = $this->scope->current($data->preparation_uuid, $bearer);
                if ($data->accepted_quote_fingerprint !== $facts['quote_fingerprint'] || $data->accepted_amount_minor !== $facts['amount_minor']
                    || $data->accepted_currency !== $facts['currency'] || $data->acknowledgement_version !== $facts['acknowledgement_version']) {
                    $this->scope->reject();
                }
                $fingerprint = $this->ledger->fingerprint($facts);
                $old = CheckoutTokenGrant::where('uuid', strtolower($data->uuid))->lockForUpdate()->first();
                if ($old !== null) {
                    if ($old->payment_dispatch_preparation_id !== $prep->id || $old->scope_fingerprint !== $fingerprint || $old->token_fingerprint !== $tokenFingerprint) {
                        $this->scope->reject();
                    }

                    // Historical replay returns no instrument and is never renewed or permission to consume again.
                    return $old;
                }
                if (PaymentDispatchAttempt::where('payment_dispatch_preparation_id', $prep->id)->lockForUpdate()->exists()) {
                    $this->scope->reject();
                }
                $now = CarbonImmutable::now('UTC');

                return CheckoutTokenGrant::create(['uuid' => strtolower($data->uuid), 'payment_dispatch_preparation_id' => $prep->id,
                    'token_fingerprint' => $tokenFingerprint, 'scope_fingerprint' => $fingerprint, 'scope' => $facts, 'issued_at' => $now, 'expires_at' => $now->addMinutes(5)]);
            }, 3);
        } catch (Throwable) {
            $this->scope->reject();
        }
    }
}
