@php
    /** @var \App\Models\Lead $lead */
    /** @var array $payload */
    /** @var string $environment */
@endphp

<x-layouts.minimal title="Clinical intake">
    <div class="prx-page">
        <div class="prx-shell">

            <header class="prx-header">
                <p class="prx-eyebrow">Step 2 of 2 · Clinical intake</p>
                <h1 class="prx-title brand-display">Tell us about your health</h1>
                <p class="prx-lede">
                    The form below is hosted by our partner clinic on a HIPAA-covered platform.
                    Your details and selections have already been passed across, so you start at
                    the clinical questions.
                </p>
            </header>

            <x-prx.embed
                :payload="$payload"
                :environment="$environment" />

            <p class="prx-footnote">
                Ref <code>{{ \Illuminate\Support\Str::limit($lead->uuid, 8, '') }}…</code>
                · Subtotal ${{ number_format((float) ($lead->cart_subtotal ?? 0), 2) }}
            </p>
        </div>
    </div>

    @push('head')
        <style>
            /* Mobile-first: the phone is the common case on this page, and the
               embed inside it is a full-width form. Padding stays small so the
               provider's form gets the width, and grows only once there is
               room to spare. */
            .prx-page {
                min-height: 100vh;
                padding: 28px 0 56px;
            }

            .prx-shell {
                max-width: 860px;
                margin: 0 auto;
                padding: 0 16px;
            }

            .prx-header {
                margin-bottom: 24px;
            }

            .prx-eyebrow {
                margin: 0 0 8px;
                font-size: 11px;
                letter-spacing: 0.12em;
                text-transform: uppercase;
                opacity: 0.55;
            }

            .prx-title {
                margin: 0;
                font-size: 28px;
                font-weight: 600;
                line-height: 1.15;
                text-wrap: balance;
            }

            .prx-lede {
                max-width: 60ch;
                margin: 12px 0 0;
                font-size: 15px;
                line-height: 1.55;
                opacity: 0.7;
            }

            .prx-footnote {
                margin: 20px 0 0;
                font-size: 12px;
                text-align: center;
                opacity: 0.45;
            }

            .prx-footnote code {
                font-family: ui-monospace, "SF Mono", Menlo, monospace;
            }

            @media (min-width: 768px) {
                .prx-page {
                    padding: 56px 0 80px;
                }

                .prx-shell {
                    padding: 0 32px;
                }

                .prx-title {
                    font-size: 38px;
                }

                .prx-header {
                    margin-bottom: 32px;
                }
            }
        </style>
    @endpush
</x-layouts.minimal>
