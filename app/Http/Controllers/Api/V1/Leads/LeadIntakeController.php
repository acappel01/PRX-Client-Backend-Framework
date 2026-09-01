<?php

namespace App\Http\Controllers\Api\V1\Leads;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder;
use App\Settings\BillingSettings;
use App\Settings\IntegrationSettings;
use Illuminate\Http\JsonResponse;

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
}
