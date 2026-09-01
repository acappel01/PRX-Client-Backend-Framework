<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Actions\Checkout\SubmitLocalCheckoutAction;
use App\Actions\Checkout\SubmitPrescribeRxCheckoutAction;
use App\Data\Checkout\CheckoutData;
use App\Data\Checkout\GatewayContextData;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Checkout\CheckoutResource;
use App\Models\Commerce\Cart;
use App\Models\Lead;
use App\Services\Payments\PaymentGatewayManager;
use App\Settings\BillingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * POST /api/v1/checkout
 *
 * Submits a cart + lead to the configured checkout provider and returns
 * the order UUID + a checkout path the frontend uses to load the next step.
 *
 * The active path is controlled by BillingSettings::$checkout_path:
 *   'prx'   — Forwards to Prescribe-Rx; PRX embed handles payment + intake.
 *   'local' — Charges locally via the default merchant account gateway.
 */
class CheckoutController extends ApiController
{
    public function __construct(
        private readonly SubmitPrescribeRxCheckoutAction $prescribeRx,
        private readonly SubmitLocalCheckoutAction $localCheckout,
        private readonly PaymentGatewayManager $gatewayManager,
        private readonly BillingSettings $billingSettings,
    ) {}

    /**
     * Get the active payment gateway configuration for the checkout frontend.
     *
     * Returns the gateway provider name, the public/publishable key required to
     * initialize the gateway's client-side tokenization SDK (Accept.js, Collect.js,
     * Stripe.js, Square Web Payments), and the environment so the frontend can
     * target the correct SDK URL and form endpoint.
     *
     * @tags Checkout
     *
     * @unauthenticated
     */
    public function gatewayConfig(): JsonResponse
    {
        try {
            $account = $this->gatewayManager->defaultAccount();
        } catch (Throwable) {
            return $this->error('No active payment gateway is configured.', 503);
        }

        $context = GatewayContextData::forAccount($account);

        // A gateway that cannot tokenise is worse than none: the storefront
        // would render a card form that fails at submit, after the visitor has
        // typed their number. Authorize.Net needs its api_login_id beside the
        // client key, Square its location id — a missing half is a 503, not a
        // partial payload.
        if (! $context->isUsable()) {
            return $this->error('The configured payment gateway is missing credentials required to accept a card.', 503);
        }

        return $this->success($context->toArray());
    }

    /**
     * Submit a cart to checkout.
     *
     * Submits the identified cart and lead to the configured checkout provider (prescribe-rx
     * or local gateway). Returns the order UUID and a checkout path for the frontend to load
     * the next step. Requires matching cart_ulid and lead_uuid from the same session.
     *
     * @tags Checkout
     *
     * @unauthenticated
     */
    public function store(Request $request): JsonResponse
    {
        $data = CheckoutData::validateAndCreate($request->all());

        $cart = Cart::where('ulid', $data->cart_ulid)->firstOrFail();
        $lead = Lead::where('uuid', $data->lead_uuid)->firstOrFail();

        // Verify the lead was created in the same cart session.
        // cart_ulid is captured from X-Cart-Token when a lead is created,
        // so a caller who only knows one of the two identifiers cannot pair them.
        if (filled($lead->cart_ulid) && ! hash_equals($lead->cart_ulid, $cart->ulid)) {
            abort(403, 'Cart and lead do not belong to the same session.');
        }

        try {
            if ($this->billingSettings->checkout_path === 'local') {
                if (empty($data->payment_method)) {
                    return $this->error('payment_method is required for local checkout.', 422);
                }

                $result = $this->localCheckout->execute($cart, $lead, $data->payment_method);
            } else {
                $result = $this->prescribeRx->execute($cart, $lead, $data->intake_answers);
            }
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Log::error('Checkout failed', [
                'cart_ulid' => $data->cart_ulid,
                'lead_uuid' => $data->lead_uuid,
                'checkout_path' => $this->billingSettings->checkout_path,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Checkout could not be completed. Please try again.', 503);
        }

        return (new CheckoutResource($result))
            ->response()
            ->setStatusCode(201);
    }
}
