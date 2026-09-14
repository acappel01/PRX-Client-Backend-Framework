<?php

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\ManageIntegrations;
use App\Models\ProviderInstance;
use App\Models\User;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckoutProviderInstanceSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_checkout_selection_persists_without_provider_calls_and_rejects_unknown_keys(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create()->refresh();
        $user->assignRole('super_admin');
        $this->actingAs($user);
        ProviderInstance::create([
            'key' => 'checkout-client', 'provider' => 'prescribe_rx', 'environment' => 'sandbox',
            'account_type' => 'client', 'external_account_id' => 'test-client-id',
        ]);
        Livewire::test(ManageIntegrations::class)->fillForm([
            'prescribe_rx_provider_instance_key' => 'checkout-client',
            'prescribe_rx_client_id' => 'test-client-id',
        ])->call('save')->assertHasNoFormErrors();
        $this->assertSame('checkout-client', app(IntegrationSettings::class)->refresh()->prescribe_rx_provider_instance_key);
        Livewire::test(ManageIntegrations::class)->fillForm([
            'prescribe_rx_provider_instance_key' => 'unknown-instance',
        ])->call('save')->assertHasFormErrors(['prescribe_rx_provider_instance_key']);
        $this->assertSame('checkout-client', app(IntegrationSettings::class)->refresh()->prescribe_rx_provider_instance_key);
        Http::assertNothingSent();
    }
}
