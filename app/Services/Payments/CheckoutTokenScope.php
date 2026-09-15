<?php

namespace App\Services\Payments;

use App\Enums\Patient\TwoFactorPolicy;
use App\Enums\Payments\PaymentOperationState;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\Payments\GatewayAccountBinding;
use App\Models\Payments\PaymentDispatchPreparation;
use App\Models\Payments\PaymentIntent;
use App\Models\Payments\PaymentOperation;
use App\Services\Patient\PatientSessionLifetime;
use App\Settings\PortalSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Internal persisted-bearer boundary. Does not accept Request::user(), email claims, or transient tokens as proof. */
final class CheckoutTokenScope
{
    public function __construct(private readonly PaymentOperationReferenceScope $scope, private readonly PaymentLedgerScope $ledger) {}

    public function current(string $preparationUuid, #[\SensitiveParameter] string $bearer): array
    {
        if (DB::transactionLevel() === 0) {
            $this->reject();
        }
        $identity = PaymentDispatchPreparation::where('uuid', $preparationUuid)->firstOrFail();
        app(PaymentAssociationLock::class)->acquire($identity->canonical_account_key, $identity->environment);
        $opIdentity = PaymentOperation::findOrFail($identity->payment_operation_id);
        $intentIdentity = PaymentIntent::findOrFail($opIdentity->payment_intent_id);
        Order::whereKey($intentIdentity->order_id)->lockForUpdate()->firstOrFail();
        $intent = PaymentIntent::whereKey($intentIdentity->id)->lockForUpdate()->firstOrFail();
        $op = PaymentOperation::whereKey($opIdentity->id)->lockForUpdate()->firstOrFail();
        $prep = PaymentDispatchPreparation::whereKey($identity->id)->lockForUpdate()->firstOrFail();
        $binding = GatewayAccountBinding::findOrFail($prep->gateway_account_binding_id);
        $this->scope->assertCurrent($op, $binding);
        $customer = Customer::whereKey($intent->customer_id)->lockForUpdate()->firstOrFail();
        $order = Order::whereKey($intent->order_id)->lockForUpdate()->firstOrFail();
        if (strlen($bearer) > 1024 || ! preg_match('/\A([1-9][0-9]{0,18})\|([!-~]{16,512})\z/', $bearer, $parts)) {
            $this->reject();
        }
        // Current locking read rechecks the actual bearer hash, so deleted/revoked/changed sessions cannot replay.
        $session = PersonalAccessToken::whereKey($parts[1])->lockForUpdate()->first();
        if ($session === null || ! hash_equals($session->token, hash('sha256', $parts[2])) || ! $session->can('patient:*')
            || $session->tokenable_type !== (new Patient)->getMorphClass()
            || ($session->expires_at !== null && $session->expires_at->lessThanOrEqualTo(now()))
            || (config('sanctum.expiration') && $session->created_at->lessThanOrEqualTo(now()->subMinutes((int) config('sanctum.expiration'))))) {
            $this->reject();
        }
        // Match the portal's application-specific Sanctum policy without deleting,
        // auditing or renewing sessions from inside a payment transaction.
        if (app(PatientSessionLifetime::class)->expiryReason($session) !== null) {
            $this->reject();
        }
        $patient = Patient::whereKey($session->tokenable_id)->lockForUpdate()->first();
        if ($patient === null || $patient->email_verified_at === null || $customer->portal_account_id !== $patient->id
            || $order->customer_id !== $customer->id || ($order->patient_id !== null && $order->patient_id !== $patient->id)
            || (app(PortalSettings::class)->twoFactorPolicy() === TwoFactorPolicy::Required && ! $patient->hasTwoFactor())) {
            $this->reject();
        }
        $f = $prep->prepared_scope;
        if ($op->state !== PaymentOperationState::Prepared || ! in_array($op->purpose->value, ['sale', 'authorize'], true)
            || $op->original_operation_id !== null || $prep->state !== 'prepared_only'
            || $f['operation_uuid'] !== $op->uuid || $f['operation_request_fingerprint'] !== $op->request_fingerprint
            || $f['order_snapshot_fingerprint'] !== $intent->order_snapshot_fingerprint
            || $f['merchant_binding_fingerprint'] !== $intent->merchant_binding_fingerprint
            || $f['amount_minor'] !== $op->amount_minor || $f['currency'] !== $intent->currency
            || PaymentOperation::where('payment_intent_id', $intent->id)->where('state', PaymentOperationState::Uncertain->value)->lockForUpdate()->exists()) {
            $this->reject();
        }
        $facts = ['preparation_uuid' => $prep->uuid, 'preparation_fingerprint' => $prep->request_fingerprint,
            'operation_uuid' => $op->uuid, 'operation_fingerprint' => $op->request_fingerprint, 'intent_uuid' => $intent->uuid,
            'customer_uuid' => $customer->uuid, 'patient_uuid' => $patient->uuid, 'patient_id' => $patient->id, 'session_id' => $session->id,
            'session_fingerprint' => $this->ledger->fingerprint($session->only(['id', 'token', 'tokenable_type', 'tokenable_id', 'abilities', 'expires_at', 'created_at'])),
            'order_uuid' => $order->uuid, 'quote_fingerprint' => $intent->order_snapshot_fingerprint,
            'gateway_account_binding_id' => $binding->id, 'canonical_account_key' => $binding->canonical_account_key, 'environment' => $binding->environment,
            'merchant_binding_fingerprint' => $intent->merchant_binding_fingerprint, 'merchant_reference' => $f['merchant_reference'],
            'purpose' => $op->purpose->value, 'amount_minor' => $op->amount_minor, 'currency' => $intent->currency, 'acknowledgement_version' => 'checkout_payment_v1'];

        return [$prep, $facts];
    }

    public function tokenFingerprint(#[\SensitiveParameter] string $token): string
    {
        if (! preg_match('/\A[A-Za-z0-9+\/=_.-]{1,8192}\z/', $token)) {
            $this->reject();
        }

        return hash_hmac('sha256', 'authorize-net-opaque:v1:'.$token, (string) config('app.key'));
    }

    public function reject(): never
    {
        throw ValidationException::withMessages(['checkout_authorization' => 'Checkout authorization is unavailable, consumed, or its authenticated scope changed.']);
    }
}
