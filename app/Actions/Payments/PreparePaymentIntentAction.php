<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentIntentData;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Payments\MerchantAccount;
use App\Models\Payments\PaymentIntent;
use App\Services\Payments\PaymentLedgerScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Trusted local callers only. No authorization, executor claim or gateway request. */
class PreparePaymentIntentAction
{
    public function __construct(private readonly PaymentLedgerScope $scope) {}

    public function execute(PaymentIntentData $data): PaymentIntent
    {
        $input = $data->toArray();
        $input['uuid'] = strtolower($data->uuid);
        Validator::make($input, [
            'uuid' => ['required', 'uuid'],
            'order_id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'merchant_account_id' => ['required', 'integer', 'min:1'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'executor_key' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_.:-]*\z/'],
        ])->validate();
        $fingerprint = $this->scope->fingerprint($input);

        return DB::transaction(function () use ($input, $fingerprint): PaymentIntent {
            $existing = PaymentIntent::query()->where('uuid', $input['uuid'])->first();
            if ($existing !== null) {
                return $this->replay($existing, $fingerprint);
            }
            $merchant = MerchantAccount::query()->whereKey($input['merchant_account_id'])->lockForUpdate()->first();
            $order = Order::query()->whereKey($input['order_id'])->lockForUpdate()->first();
            $customer = Customer::query()->whereKey($input['customer_id'])->lockForUpdate()->first();
            if ($merchant === null || ! $merchant->is_active || $order === null || $customer === null
                || $order->customer_id !== $customer->id || $order->currency !== $input['currency']
                || ($order->patient_id !== null && $order->patient_id !== $customer->portal_account_id)
                || $merchant->gateway_provider->value !== $input['gateway_provider']
                || $merchant->environment->value !== $input['environment']) {
                throw ValidationException::withMessages(['payment' => 'Payment order, customer, merchant or currency scope does not match.']);
            }
            try {
                return DB::transaction(fn () => PaymentIntent::create($input + [
                    'order_uuid' => $order->uuid, 'customer_uuid' => $customer->uuid,
                    'merchant_account_uuid' => $merchant->uuid,
                    'request_fingerprint' => $fingerprint,
                    'merchant_binding_fingerprint' => $this->scope->merchantFingerprint($merchant),
                    'order_snapshot_fingerprint' => $this->scope->orderFingerprint($order, $customer),
                    'created_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $existing = PaymentIntent::query()->where('uuid', $input['uuid'])->lockForUpdate()->first();
                if ($existing === null) {
                    throw $exception;
                }

                return $this->replay($existing, $fingerprint);
            }
        }, 3);
    }

    private function replay(PaymentIntent $intent, string $fingerprint): PaymentIntent
    {
        if (! hash_equals($intent->request_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['payment' => 'This payment intent identity already describes a different request.']);
        }

        return $intent;
    }
}
