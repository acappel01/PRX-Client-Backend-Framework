<?php

namespace Tests\Feature\Checkout;

use App\Actions\Settings\UpdateBillingSettingsAction;
use App\Data\Settings\BillingSettingsData;
use App\Settings\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A Filament settings page saves through the action + DTO below, so a property
 * present on the settings class but MISSING from the DTO renders a control,
 * accepts a value, reports success and writes nothing.
 *
 * That has happened three times in this repo. These assert the STORED value
 * rather than the return, because the return is what looked fine each time.
 */
class BillingSettingsRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_payment_collector_actually_persists(): void
    {
        app(UpdateBillingSettingsAction::class)->execute(new BillingSettingsData(
            checkout_path: 'prx',
            payment_collector: 'capture_then_intake',
        ));

        $stored = app(BillingSettings::class)->refresh();

        $this->assertSame('capture_then_intake', $stored->payment_collector);
        $this->assertTrue($stored->collectsPaymentOnSite());
    }

    public function test_it_round_trips_back_to_the_provider(): void
    {
        $action = app(UpdateBillingSettingsAction::class);

        $action->execute(new BillingSettingsData(checkout_path: 'prx', payment_collector: 'authorize_then_capture'));
        $action->execute(new BillingSettingsData(checkout_path: 'prx', payment_collector: 'provider'));

        $stored = app(BillingSettings::class)->refresh();

        $this->assertSame('provider', $stored->payment_collector);
        $this->assertFalse($stored->collectsPaymentOnSite());
    }

    /**
     * The two sequences differ in WHEN money moves, and the storefront needs to
     * know which: charge now, or authorise-and-vault for the provider to
     * capture after a clinician has reviewed the case.
     */
    public function test_each_sequence_reports_the_bypass_shape_the_intake_expects(): void
    {
        $action = app(UpdateBillingSettingsAction::class);

        $action->execute(new BillingSettingsData(checkout_path: 'prx', payment_collector: 'capture_then_intake'));
        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.checkout.payment.bypass', 'transaction')
            ->assertJsonPath('data.checkout.payment.collect_on_site', true);

        Cache::flush();

        $action->execute(new BillingSettingsData(checkout_path: 'prx', payment_collector: 'authorize_then_capture'));
        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.checkout.payment.bypass', 'vaultedCard')
            ->assertJsonPath('data.checkout.payment.collect_on_site', true);
    }

    /**
     * With the provider collecting there is no bypass and no card form — the
     * storefront renders no payment UI at all.
     */
    public function test_the_provider_sequence_has_no_bypass_and_no_on_site_form(): void
    {
        app(UpdateBillingSettingsAction::class)->execute(new BillingSettingsData(
            checkout_path: 'prx',
            payment_collector: 'provider',
        ));

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.checkout.payment.bypass', null)
            ->assertJsonPath('data.checkout.payment.collect_on_site', false);
    }

    /**
     * `storefront` was the single flag that predated the split into two
     * sequences. It must not resolve to "the provider collects" — that would
     * silently stop charging on an install that was.
     */
    public function test_the_superseded_storefront_value_still_collects_on_site(): void
    {
        $settings = app(BillingSettings::class);
        $settings->payment_collector = 'storefront';

        $this->assertTrue($settings->collectsPaymentOnSite());
        $this->assertSame('transaction', $settings->paymentCollector()->bypassShape());
    }

    /**
     * The storefront reads this to decide whether to render its own payment
     * step, and the provider's embed skips its payment step on the same
     * setting — so a stale value has the two disagreeing about who collects.
     */
    public function test_the_public_config_reports_the_stored_collector(): void
    {
        app(UpdateBillingSettingsAction::class)->execute(new BillingSettingsData(
            checkout_path: 'prx',
            payment_collector: 'capture_then_intake',
        ));

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.checkout.payment.collector', 'capture_then_intake')
            ->assertJsonPath('data.checkout.payment.collect_on_site', true);
    }
}
