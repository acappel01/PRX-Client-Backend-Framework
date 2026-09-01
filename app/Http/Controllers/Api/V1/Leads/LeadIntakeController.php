<?php

namespace App\Http\Controllers\Api\V1\Leads;

use App\Actions\Leads\MarkLeadHandedOffAction;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder;
use App\Settings\BillingSettings;
use App\Settings\IntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the frontend needs to host the clinical intake embed itself.
 *
 * WHY THE FRONTEND HOSTS IT. The embed used to live on a server-rendered page
 * in this admin domain, which put the clinical step outside the storefront —
 * away from its branding, its compliance copy, and its cart. A visitor there
 * had no way back and lost everything they had typed if they pressed back.
 * This endpoint hands the same payload to the storefront so the embed renders
 * inside a page that owns those things.
 *
 * The lead UUID is the credential, exactly as it is for `show` and `plan`.
 * This returns the same PII those already expose, to the same holder of the
 * same opaque id — it widens nothing.
 */
class LeadIntakeController extends Controller
{
    /**
     * Embed configuration, cart summary and payment mode for one lead.
     *
     * @tags Leads
     *
     * @unauthenticated
     */
    public function show(
        Lead $lead,
        PrxEmbedPayloadBuilder $payloads,
        IntegrationSettings $integrations,
        BillingSettings $billing,
    ): JsonResponse {
        // THE GATE. When this deployment collects payment, a lead may not
        // reach a single clinical question until the card has actually
        // succeeded. A declined card that still produced a completed encounter
        // means a clinician's time spent and product shipped for money nobody
        // took — so this refuses rather than rendering an intake the storefront
        // would happily host.
        //
        // Enforced HERE and not only in the page, because the page is a URL
        // anyone holding the lead uuid can open directly.
        if ($billing->collectsPaymentOnSite() && ! $lead->payment_status->isSettled()) {
            return response()->json([
                'message' => 'This order has no completed payment, so the clinical intake is not available.',
                'errors' => ['payment' => ['Payment must be completed before the medical intake.']],
            ], 402);
        }

        return response()->json([
            'data' => [
                'embed' => $payloads->forLead($lead),
                'environment' => $integrations->prescribe_rx_environment,

                // Where payment is taken. The embed renders its own payment
                // step only when the provider is collecting; when this side
                // collects, that step is skipped and the storefront shows its
                // own. One setting, so the two can never both claim it.
                'payment' => [
                    'collector' => $billing->paymentCollector()->value,
                    'collect_on_site' => $billing->collectsPaymentOnSite(),
                ],

                // Enough to render a summary beside the embed, so a visitor
                // can see what they are buying and get back to the cart
                // without losing the page.
                'cart' => [
                    'items' => collect($lead->cart_items ?? [])->map(fn (array $line): array => [
                        'name' => $line['name'] ?? null,
                        'quantity' => $line['quantity'] ?? 1,
                        'unit_price' => isset($line['unit_price']) ? (float) $line['unit_price'] : null,
                    ])->all(),
                    'subtotal' => $lead->cart_subtotal !== null ? (float) $lead->cart_subtotal : null,
                ],
            ],
        ]);
    }

    /**
     * Advisory "the visitor appears to have submitted" ping from the embed's
     * onComplete, so the storefront can flip to a thank-you state without
     * waiting on the webhook.
     *
     * NOT AUTHORITATIVE. The body is client-supplied and unverified; the
     * signed webhook remains the source of truth for status, encounter
     * creation and fulfilment. Never make a billing or fulfilment decision
     * from this.
     *
     * The same ping already existed for the server-rendered handoff page, but
     * that route is session- and CSRF-protected on the web middleware — it is
     * unreachable from the storefront's origin, so moving the embed there
     * dropped it silently. This is the cross-origin equivalent, with the lead
     * UUID in the path as the credential rather than in the body.
     *
     * @tags Leads
     *
     * @unauthenticated
     */
    public function complete(Request $request, Lead $lead, MarkLeadHandedOffAction $action): JsonResponse
    {
        $data = $request->validate([
            'encounter_id' => ['nullable', 'string', 'max:64'],
            'patient_id' => ['nullable', 'string', 'max:64'],
        ]);

        $action->execute(
            $lead,
            $data['encounter_id'] ?? null,
            $data['patient_id'] ?? null,
        );

        return response()->json(['data' => ['ok' => true]]);
    }
}
