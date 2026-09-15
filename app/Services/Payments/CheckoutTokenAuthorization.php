<?php

namespace App\Services\Payments;

use App\Contracts\Payments\AuthorizeNetInstrumentAuthorization;
use App\Data\Payments\AuthorizeNetOpaqueAuthorization;
use App\Data\Payments\PaymentDispatchRequest;
use App\Models\Payments\CheckoutTokenConsumption;
use App\Models\Payments\CheckoutTokenGrant;
use App\Models\Payments\PaymentDispatchAttempt;
use App\Models\Payments\PaymentTransportInvocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Per-request explicit resolver. No default binding; secrets stay in memory and cannot be serialized. */
final class CheckoutTokenAuthorization implements AuthorizeNetInstrumentAuthorization
{
    public function __construct(private readonly string $grantUuid,
        #[\SensitiveParameter] private readonly string $bearer, #[\SensitiveParameter] private readonly string $opaqueToken,
        private readonly CheckoutTokenScope $scope, private readonly PaymentLedgerScope $ledger) {}

    public function authorize(PaymentDispatchRequest $request, string $customerUuid): AuthorizeNetOpaqueAuthorization
    {
        try {
            if (DB::transactionLevel() !== 0 || config('payments.checkout_token_authorization_enabled', false) !== true) {
                $this->scope->reject();
            }
            $tokenFingerprint = $this->scope->tokenFingerprint($this->opaqueToken);
            $expires = DB::transaction(function () use ($request, $customerUuid, $tokenFingerprint): CarbonImmutable {
                [$prep,$facts] = $this->scope->current($request->preparation_uuid, $this->bearer);
                $grant = CheckoutTokenGrant::where('uuid', $this->grantUuid)->lockForUpdate()->firstOrFail();
                if ($grant->payment_dispatch_preparation_id !== $prep->id || $grant->token_fingerprint !== $tokenFingerprint
                    || $grant->scope_fingerprint !== $this->ledger->fingerprint($facts)
                    || $grant->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC')) || $facts['customer_uuid'] !== $customerUuid) {
                    $this->scope->reject();
                }
                $attempt = PaymentDispatchAttempt::where('uuid', $request->attempt_uuid)->lockForUpdate()->firstOrFail();
                if ($attempt->status !== 'claimed' || $attempt->transport_key !== 'authorize_net.xml.v1'
                    || $attempt->payment_dispatch_preparation_id !== $prep->id || $attempt->request_fingerprint !== $request->request_fingerprint
                    || $this->ledger->fingerprint($attempt->request_facts) !== $request->request_fingerprint
                    || ! PaymentTransportInvocation::where('payment_dispatch_attempt_id', $attempt->id)->exists()) {
                    $this->scope->reject();
                }
                foreach (get_object_vars($request) as $name => $value) {
                    if (in_array($name, ['attempt_uuid', 'request_fingerprint'], true)) {
                        continue;
                    }
                    if (! array_key_exists($name, $attempt->request_facts) || $attempt->request_facts[$name] !== $value) {
                        $this->scope->reject();
                    }
                    if (array_key_exists($name, $facts) && $facts[$name] !== $value) {
                        $this->scope->reject();
                    }
                }
                if ($request->original_transaction_id !== null) {
                    $this->scope->reject();
                }
                CheckoutTokenConsumption::create(['checkout_token_grant_id' => $grant->id, 'payment_dispatch_attempt_id' => $attempt->id, 'consumed_at' => CarbonImmutable::now('UTC')]);

                return $grant->expires_at;
            }, 3);

            // Consumption is committed. Any subsequent crash/timeout keeps this token consumed permanently.
            return new AuthorizeNetOpaqueAuthorization($request->request_fingerprint, $request->preparation_uuid, $request->canonical_account_key, $customerUuid, $expires, $this->opaqueToken);
        } catch (Throwable) {
            $this->scope->reject();
        }
    }

    public function __debugInfo(): array
    {
        return ['authorization' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Ephemeral checkout authorization cannot be serialized.');
    }
}
