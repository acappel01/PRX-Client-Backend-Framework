{{--
    PrescribeRx embed iframe wrapper.

    Required props:
      $payload — array from PrxEmbedPayloadBuilder->forLead($lead). Keys:
                 embedCode, prefill, packages, products, productTypes,
                 planIds, skipSteps, metadata.
      $environment — 'sandbox' | 'production' (drives which sdk.js + iframe
                     origin to load).
      $completeUrl — POST endpoint our parent page pings with the encounter
                     ID returned by onComplete (best-effort UI sync; the
                     webhook is source of truth).
--}}
@props([
    'payload',
    'environment' => 'sandbox',
    'completeUrl' => null,
])

@php
    $sdkBase = $environment === 'production'
        ? 'https://prescribe-rx.com'
        : 'https://demo.prescribe-rx.com';

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $completeUrl ??= url('/api/internal/checkout/embed-complete');
@endphp

@once
    @push('head')
        <style>
            /* THE HOST MUST NOT CONSTRAIN THE IFRAME.
               The embed posts `prescriberx-iframe-resize` and the SDK sets the
               iframe height to its content, so any max-height / overflow here
               reintroduces the nested scrollbar this styling exists to remove.
               Only a WIDTH is asserted. */
            .prx-embedHost {
                width: 100%;
            }

            .prx-embedHost iframe {
                display: block;
                width: 100%;
                border: 0;
                /* Belt and braces: if a resize message is ever missed, the
                   frame still fills the viewport rather than collapsing to a
                   sliver — but it is never CAPPED, so growth still wins. */
                min-height: 420px;
                background: transparent;
            }

            .prx-embedLoading {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 320px;
                font-size: 14px;
                opacity: 0.55;
            }

            .prx-embedMissing {
                padding: 32px 20px;
                font-size: 14px;
                line-height: 1.6;
                text-align: center;
                border: 1px dashed rgb(190 40 40 / 45%);
                border-radius: 10px;
                background: rgb(190 40 40 / 4%);
            }

            .prx-embedMissing a {
                text-decoration: underline;
            }
        </style>
    @endpush
@endonce

<div class="prx-embedWrap">
    @if (empty($payload['embedCode']))
        <div class="prx-embedMissing">
            <strong>Embed code not configured</strong><br>
            Set <code>prescribe_rx_embed_code</code> in
            <a href="{{ url('/admin/settings/integrations') }}" target="_blank" rel="noopener">integration settings</a>
            to load the clinical intake.
        </div>
    @else
        {{--
            The SDK injects the iframe inside this element.

            NO BORDER, NO FIXED HEIGHT, NO overflow-hidden. The embed posts
            `prescriberx-iframe-resize` messages and the SDK grows the iframe
            to its content, so any height cap here produces a scrollbar INSIDE
            a scrollbar — the ugly nested-scroll effect. A border likewise
            frames the provider's form as a foreign object rather than letting
            it read as part of this page.
        --}}
        <div id="prx-intake" class="prx-embedHost">
            <div class="prx-embedLoading">
                <span>Loading clinical intake…</span>
            </div>
        </div>
    @endif
</div>

@push('scripts')
    @if (! empty($payload['embedCode']))
        <script src="{{ $sdkBase }}/embed/sdk.js" async></script>
        <script>
            (function () {
                const payload = @json($payload);
                const completeUrl = @json($completeUrl);
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

                // Guard: PRX may fire onReady more than once if the iframe's
                // inner Livewire components remount. Without this flag, every
                // re-mount would re-fire prefill / selectProducts /
                // setSkipSteps — each call is a postMessage + Livewire
                // roundtrip on the PRX side, producing a request storm.
                let isConfigured = false;
                let isInitialized = false;

                function bootEmbed() {
                    if (!window.PrescribeRx || typeof window.PrescribeRx.init !== 'function') {
                        // SDK not yet ready — try again on next frame.
                        return setTimeout(bootEmbed, 50);
                    }

                    if (isInitialized) return;
                    isInitialized = true;

                    window.PrescribeRx.init('prx-intake', {
                        embedCode: payload.embedCode,

                        // Passed at INIT, not only after ready. The universal
                        // intake decides which steps to render from the
                        // product / product-type / package it is given, so it
                        // has to know before it computes its step list — a
                        // post-ready push can arrive after that decision.
                        // The onReady block below re-applies the same values
                        // as a belt-and-braces fallback; it is guarded so a
                        // remount cannot turn it into a request storm.
                        packages: payload.packages || [],
                        products: payload.products || [],
                        productTypes: payload.productTypes || [],
                        prefill: payload.prefill || {},
                        skipSteps: payload.skipSteps || [],

                        // Grow from a modest floor rather than reserving a
                        // desktop-sized box on a phone.
                        minHeight: '420px',

                        onReady() {
                            // First ready only — bail on subsequent fires.
                            // The SDK's internal state already reflects the
                            // values we pushed; re-pushing them just churns.
                            if (isConfigured) {
                                return;
                            }
                            isConfigured = true;

                            try {
                                if (payload.prefill && Object.keys(payload.prefill).length) {
                                    window.PrescribeRx.prefill(payload.prefill);
                                }
                                if (payload.packages && payload.packages.length) {
                                    window.PrescribeRx.selectPackages(payload.packages);
                                }
                                if (payload.products && payload.products.length) {
                                    window.PrescribeRx.selectProducts(payload.products);
                                }
                                if (payload.productTypes && payload.productTypes.length) {
                                    // Items whose dose the prescriber chooses.
                                    window.PrescribeRx.selectProductTypes(payload.productTypes);
                                }
                                if (payload.planIds && payload.planIds.length === 1) {
                                    window.PrescribeRx.selectPlan(payload.planIds[0]);
                                }
                                if (payload.skipSteps && payload.skipSteps.length) {
                                    window.PrescribeRx.setSkipSteps(payload.skipSteps);
                                }
                            } catch (e) {
                                console.error('[prx-embed] error during onReady:', e);
                                // Allow retry on next ready if the first run threw.
                                isConfigured = false;
                            }
                        },

                        onStepChange(data) {
                            // Useful hook for analytics later. Avoid logging
                            // payload bodies — they may contain PHI.
                            console.debug('[prx-embed] step', data.step + '/' + data.total, data.stepName);
                        },

                        onComplete(data) {
                            console.info('[prx-embed] intake complete', {
                                encounter_id: data?.encounter_id,
                                patient_id: data?.patient_id,
                            });

                            // Best-effort POST to our server so the UI can
                            // update before the webhook lands. The server
                            // treats this as advisory — webhook is source of
                            // truth for status. Never trust onComplete data
                            // for billing or fulfillment decisions.
                            if (completeUrl && csrfToken) {
                                fetch(completeUrl, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': csrfToken,
                                    },
                                    body: JSON.stringify({
                                        lead_uuid: payload.metadata?.lead_uuid,
                                        encounter_id: data?.encounter_id,
                                        patient_id: data?.patient_id,
                                    }),
                                    credentials: 'same-origin',
                                }).catch((err) => console.warn('[prx-embed] complete-ping failed', err));
                            }
                        },

                        onError(data) {
                            console.error('[prx-embed] error:', data?.message || data);
                        },
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', bootEmbed);
                } else {
                    bootEmbed();
                }
            })();
        </script>
    @endif
@endpush
