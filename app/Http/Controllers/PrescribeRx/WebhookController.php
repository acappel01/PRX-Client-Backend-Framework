<?php

namespace App\Http\Controllers\PrescribeRx;

use App\Actions\Webhooks\RecordInboundWebhookAction;
use App\Services\PrescribeRx\Webhooks\PrescribeRxWebhookParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * POST /api/webhooks/prescribe-rx — the one prescribe-rx webhook receiver.
 *
 * The signature (`X-PrescribeRx-Signature: sha256=<hmac of the raw body>`) is
 * verified upstream by `VerifyPrescribeRxSignature`; nothing here runs for an
 * unsigned request, so nothing is ever recorded for one.
 *
 * This controller only RECORDS the event and queues it
 * (`RecordInboundWebhookAction` → `ProcessInboundWebhookEvent` →
 * `PrescribeRxWebhookHandler`). Acting on it happens off the request, so a
 * slow or failing handler can never make the provider time out, retry, or
 * count a failure toward disabling the subscription.
 *
 * Status codes are the contract with the sender, which treats any non-2xx as a
 * failed delivery and disables a subscription after 50 in a row:
 *   200 — recorded, or a duplicate of a delivery already recorded;
 *   400 — signed, but not a prescribe-rx event (no `event`), so a retry cannot help;
 *   401 / 503 — from the signature middleware (bad signature / no secret set);
 *   500 — the event could not be recorded, so let the provider retry.
 */
class WebhookController
{
    public function __construct(
        protected PrescribeRxWebhookParser $parser,
        protected RecordInboundWebhookAction $record,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $envelope = json_decode($request->getContent(), true);

        if (! is_array($envelope)) {
            return response()->json(['error' => 'body is not a JSON object'], 400);
        }

        try {
            $parsed = $this->parser->parse($envelope);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $event = $this->record->execute('prescribe-rx', $parsed, $request->getContent());

        return response()->json(['ok' => true, 'duplicate' => $event === null]);
    }
}
