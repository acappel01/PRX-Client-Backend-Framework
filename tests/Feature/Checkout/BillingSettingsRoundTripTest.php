<?php

namespace Tests\Feature\Checkout;

use App\Actions\Settings\UpdateBillingSettingsAction;
use App\Data\Settings\BillingSettingsData;
use App\Settings\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            payment_collector: 'storefront',
        ));

        $stored = app(BillingSettings::class)->refresh();

        $this->assertSame('storefront', $stored->payment_collector);
        $this->assertTrue($stored->collectsPaymentOnSite());
    }

    public function test_it_round_trips_back_to_the_provider(): void
    {
        $action = app(UpdateBillingSettingsAction::class);

        $action->execute(new BillingSettingsData(checkout_path: 'prx', payment_collector: 'storefront'));
        $action->execute(new BillingSettingsData(checkout_path: 'prx', payment_collector: 'provider'));

        $stored = app(BillingSettings::class)->refresh();

        $this->assertSame('provider', $stored->payment_collector);
        $this->assertFalse($stored->collectsPaymentOnSite());
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
            payment_collector: 'storefront',
        ));

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.checkout.payment.collector', 'storefront')
            ->assertJsonPath('data.checkout.payment.collect_on_site', true);
    }
}
