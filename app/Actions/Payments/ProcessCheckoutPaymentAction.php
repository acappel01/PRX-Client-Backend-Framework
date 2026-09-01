<?php

namespace App\Actions\Payments;

use App\Enums\Payments\LeadPaymentStatus;
use App\Enums\Payments\PaymentCollector;
use App\Models\Lead;
use App\Models\Payments\MerchantAccount;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Takes the browser's opaque card token and turns it into money — or refuses.
 *
 * THE CARD IS ALWAYS VAULTED FIRST, IN BOTH SEQUENCES. Not only in
 * authorise-then-capture. The telehealth provider can reach a card we stored
 * only if we hand them the CIM profile it lives in, on the same merchant
 * account — so without vaulting there is no later capture and no subscription
 * rebill on their side, even when we captured the money ourselves. Vaulting
 * first also means the charge runs against a stored profile rather than a
 * one-shot token, which is what makes the profile reusable.
 *
 * THEN THE CHARGE, BEFORE THE INTAKE. Whichever sequence, the card is settled
 * before the visitor reaches a single clinical question — so a card problem is
 * raised while they are still on a checkout page that can explain it, and a
 * failed card never produces a completed encounter. A clinician's time and a
 * shipped order are the cost of getting that wrong.
 *
 * A failure here is a thrown exception, never a partially-updated lead: the
 * caller must not be able to mistake a decline for a success.
 */
class ProcessCheckoutPaymentAction
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @param  array<string, mixed>  $payment  the storefront's tokenised card
     */
    public function execute(Lead $lead, array $payment, float $amount, PaymentCollector $sequence): Lead
    {
        if (! $sequence->collectsOnSite()) {
            throw new RuntimeException('This deployment does not collect payment on site.');
        }

        $account = $this->resolveAccount($payment['merchant_account_id'] ?? null);
        $gateway = $this->gateways->forAccount($account);

        $opaque = [
            'opaque_data' => [
                'data_descriptor' => $payment['token']['data_descriptor'] ?? null,
                'data_value' => $payment['token']['data_value'] ?? null,
            ],
        ];

        if (! $opaque['opaque_data']['data_value']) {
            throw new RuntimeException('No payment token was supplied.');
        }

        // ── 1. Vault ──────────────────────────────────────────────────────
        $vault = $gateway->storePaymentMethod($account->uuid, Lead::class, (string) $lead->uuid, array_merge($opaque, [
            'customer' => [
                'email' => $lead->email,
                'description' => 'Lead '.$lead->uuid,
            ],
            'billing' => $this->billingFrom($lead),
        ]));

        if (! $vault->success) {
            $this->recordFailure($lead, $account, $vault->message ?? 'Could not store the card.');

            throw new RuntimeException($vault->message ?? 'We could not verify that card.');
        }

        $customerProfileId = $vault->rawData['customer_profile_id'] ?? null;
        $paymentProfileId = $vault->rawData['payment_profile_id'] ?? $vault->transactionId;

        // ── 2. Charge or authorise, against the stored profile ────────────
        $profilePayload = [
            'customer_profile_id' => $customerProfileId,
            'payment_profile_id' => $paymentProfileId,
        ];

        $money = $sequence === PaymentCollector::CaptureThenIntake
            ? $gateway->sale($account->uuid, number_format($amount, 2, '.', ''), 'USD', $profilePayload)
            : $gateway->authorize($account->uuid, number_format($amount, 2, '.', ''), 'USD', $profilePayload);

        if (! $money->success) {
            $this->recordFailure($lead, $account, $money->message ?? 'The payment was declined.');

            throw new RuntimeException($money->message ?? 'That card was declined.');
        }

        $status = $sequence === PaymentCollector::CaptureThenIntake
            ? LeadPaymentStatus::Captured
            : LeadPaymentStatus::Authorized;

        $lead->forceFill([
            'payment_status' => $status,
            'payment_gateway_provider' => $account->gateway_provider->value,
            'merchant_account_uuid' => $account->uuid,
            'payment_transaction_id' => $money->transactionId,
            'payment_authorization_code' => $money->rawData['auth_code'] ?? null,
            'payment_amount' => $amount,
            'provider_customer_profile_id' => $customerProfileId,
            'provider_payment_profile_id' => $paymentProfileId,
            'card_brand' => $payment['card']['brand'] ?? null,
            'card_last_four' => $payment['card']['last_four'] ?? null,
            'card_exp_month' => $payment['card']['expiration_month'] ?? null,
            'card_exp_year' => $payment['card']['expiration_year'] ?? null,
            'payment_processed_at' => now(),
            'payment_failure_reason' => null,
        ])->save();

        // Only a settled charge consumes headroom. Recording an authorisation
        // that later fails would retire an account for money never taken —
        // but an authorisation DOES hold funds, so it counts.
        app(RecordMerchantUsageAction::class)->execute($account, $amount);

        return $lead;
    }

    private function resolveAccount(?string $uuid): MerchantAccount
    {
        if (! $uuid) {
            throw new RuntimeException('The payment did not name a merchant account.');
        }

        // THE ACCOUNT THAT TOKENISED MUST BE THE ACCOUNT THAT CHARGES. The
        // token is only valid at the gateway that minted it, so this looks the
        // account up rather than re-running the router — routing may have
        // moved on while the visitor was typing.
        $account = MerchantAccount::query()->where('uuid', $uuid)->where('is_active', true)->first();

        if (! $account) {
            throw new RuntimeException('That payment method is no longer accepted. Please re-enter your card.');
        }

        return $account;
    }

    /** @return array<string, string|null> */
    private function billingFrom(Lead $lead): array
    {
        $useBilling = $lead->billing_same_as_shipping === false && $lead->billing_address_line1;

        return [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'address' => $useBilling ? $lead->billing_address_line1 : $lead->address_line1,
            'city' => $useBilling ? $lead->billing_city : $lead->city,
            'state' => $useBilling ? $lead->billing_state : $lead->state,
            'zip' => $useBilling ? $lead->billing_postal_code : $lead->postal_code,
            'country' => 'US',
        ];
    }

    private function recordFailure(Lead $lead, MerchantAccount $account, string $reason): void
    {
        $lead->forceFill([
            'payment_status' => LeadPaymentStatus::Failed,
            'payment_gateway_provider' => $account->gateway_provider->value,
            'merchant_account_uuid' => $account->uuid,
            'payment_failure_reason' => $reason,
            'payment_processed_at' => now(),
        ])->save();

        // The reason is a gateway message, never card data.
        Log::warning('Checkout payment failed before intake.', [
            'lead_uuid' => $lead->uuid,
            'merchant_account_uuid' => $account->uuid,
            'reason' => $reason,
        ]);
    }
}
