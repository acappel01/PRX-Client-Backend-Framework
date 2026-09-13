<?php

use App\Http\Controllers\PrescribeRx\EmbedCompleteController;
use App\Http\Controllers\PrescribeRx\WebhookController;
use App\Http\Middleware\VerifyPrescribeRxSignature;
use App\Models\Lead;
use App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder;
use App\Settings\IntegrationSettings;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Bare domain → the Filament panel. This app is headless apart from the panel
// and the embed page below, so the root would otherwise 404 for an operator
// who typed the hostname without /admin.
//
// NOT scoped to a domain, deliberately: prx-backend ships to more than one
// deployment and a hardcoded host would simply never match on any other one.
// Filament owns what happens next — an unauthenticated visitor is sent to the
// panel's login page, so this adds no auth surface of its own.
// ---------------------------------------------------------------------------
Route::redirect('/', '/admin')->name('home');

// ---------------------------------------------------------------------------
// Prescribe-RX checkout handoff — server-rendered embed page.
// This is the only server-rendered public page in the application; everything
// else is served by the decoupled React frontend consuming /api/v1/* routes.
// ---------------------------------------------------------------------------
Route::get('/checkout/handoff/{lead:uuid}', function (Lead $lead) {
    $settings = app(IntegrationSettings::class);
    $payload = app(PrxEmbedPayloadBuilder::class)->forLead($lead);

    return view('pages.checkout.handoff', [
        'lead' => $lead,
        'payload' => $payload,
        'environment' => $settings->prescribe_rx_environment,
    ]);
})->name('checkout.handoff');

// Internal advisory ping from the prescribe-rx embed's onComplete callback.
// NOT authoritative — the signed webhook below is the source of truth.
Route::post('/api/internal/checkout/embed-complete', EmbedCompleteController::class)
    ->name('checkout.embed-complete');

// Prescribe-RX webhook receiver — the only one. Throttled first, so an unsigned
// flood is turned away before any HMAC work; then the signature; then the event
// is recorded and queued (docs/webhooks/dev.md). CSRF exempt in bootstrap/app.php.
Route::post('/api/webhooks/prescribe-rx', WebhookController::class)
    ->middleware(['throttle:inbound-webhooks', VerifyPrescribeRxSignature::class])
    ->name('webhooks.prescribe-rx');
