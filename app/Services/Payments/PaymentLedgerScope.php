<?php

namespace App\Services\Payments;

use App\Enums\Payments\GatewayProvider;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentIntent;
use Illuminate\Validation\ValidationException;

/** Local configuration continuity, never verification of an actual gateway account. */
class PaymentLedgerScope
{
    public function fingerprint(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function merchantFingerprint(MerchantAccount $merchant): string
    {
        $keys = match ($merchant->gateway_provider) {
            GatewayProvider::AuthorizeNet => ['authnet_api_login_id', 'authnet_transaction_key', 'authnet_signature_key'],
            GatewayProvider::Nmi => ['nmi_security_key'],
            GatewayProvider::Stripe => ['stripe_secret_key', 'stripe_webhook_secret'],
            GatewayProvider::Square => ['square_access_token', 'square_location_id', 'square_webhook_signature_key'],
        };

        return $this->fingerprint([
            'id' => $merchant->id, 'uuid' => $merchant->uuid,
            'gateway' => $merchant->gateway_provider->value,
            'environment' => $merchant->environment->value,
            'endpoint' => $merchant->gateway_endpoint_url,
            'provider_merchant_profile_id' => $merchant->provider_merchant_profile_id,
            'credentials' => $merchant->only($keys),
        ]);
    }

    public function orderFingerprint(Order $order, Customer $customer): string
    {
        return $this->fingerprint([
            'order' => $order->only([
                'uuid', 'customer_id', 'patient_id', 'currency', 'subtotal', 'tax_amount',
                'shipping_amount', 'discount_amount', 'total_amount',
            ]),
            'portal_account_id' => $customer->portal_account_id,
            'items' => $order->items()->orderBy('id')->lockForUpdate()->get()
                ->map(fn ($item) => $item->only(['id', 'prescribe_rx_product_id', 'prescribe_rx_product_number', 'quantity', 'unit_price', 'line_total', 'sku', 'billing_period']))->all(),
        ]);
    }

    /** Called inside a transaction before preparing a NEW operation. */
    public function assertCurrent(PaymentIntent $intent): void
    {
        $merchant = MerchantAccount::query()->whereKey($intent->merchant_account_id)->lockForUpdate()->first();
        $order = Order::query()->whereKey($intent->order_id)->lockForUpdate()->first();
        $customer = Customer::query()->whereKey($intent->customer_id)->lockForUpdate()->first();
        if ($merchant === null || ! $merchant->is_active || $order === null || $customer === null
            || $customer->uuid !== $intent->customer_uuid || $order->uuid !== $intent->order_uuid
            || ($order->patient_id !== null && $order->patient_id !== $customer->portal_account_id)
            || ! hash_equals($intent->merchant_binding_fingerprint, $this->merchantFingerprint($merchant))
            || ! hash_equals($intent->order_snapshot_fingerprint, $this->orderFingerprint($order, $customer))) {
            throw ValidationException::withMessages(['payment' => 'The frozen payment scope has changed or is unavailable.']);
        }
    }
}
