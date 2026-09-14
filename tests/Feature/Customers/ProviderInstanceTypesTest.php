<?php

namespace Tests\Feature\Customers;

use App\Models\ProviderInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderInstanceTypesTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $key, ?string $type = null): void
    {
        $arguments = ['key' => $key, 'provider' => 'prescribe_rx', 'environment' => 'sandbox', 'external-account-id' => 'same-opaque-id'];
        if ($type !== null) {
            $arguments['--account-type'] = $type;
        }
        $this->artisan('customers:provider-instance', $arguments)->assertSuccessful();
    }

    public function test_legacy_tenant_and_typed_accounts_are_distinct_and_registration_replays(): void
    {
        $this->register('legacy');
        $this->register('client', 'client');
        $this->register('organization', 'sales_organization');
        $this->register('client', 'client');
        $this->assertDatabaseCount('provider_instances', 3);
        $this->assertSame('tenant', ProviderInstance::where('key', 'legacy')->sole()->account_type);
        $this->assertSame('client', ProviderInstance::where('key', 'client')->sole()->account_type);
        $this->assertSame('same-opaque-id', ProviderInstance::where('key', 'organization')->sole()->external_account_id);
    }

    public function test_namespace_cannot_be_registered_again_under_another_key_or_retyped(): void
    {
        $this->register('client', 'client');
        foreach ([['key' => 'different', '--account-type' => 'client'], ['key' => 'client', '--account-type' => 'sales_organization']] as $variant) {
            $this->artisan('customers:provider-instance', $variant + [
                'provider' => 'prescribe_rx', 'environment' => 'sandbox', 'external-account-id' => 'same-opaque-id',
            ])->assertFailed();
        }
        $this->assertDatabaseCount('provider_instances', 1);
        $instance = ProviderInstance::sole();
        try {
            $instance->update(['account_type' => 'sales_organization']);
            $this->fail('Persisted namespace types must be immutable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('provider_instance', $exception->errors());
        }
        $this->assertSame('client', $instance->fresh()->account_type);
    }

    public function test_invalid_type_is_rejected_and_generic_provider_types_are_supported(): void
    {
        $this->artisan('customers:provider-instance', [
            'key' => 'invalid', 'provider' => 'generic_provider', 'environment' => 'sandbox',
            'external-account-id' => 'opaque-id', '--account-type' => 'Client Account',
        ])->assertFailed();
        $this->artisan('customers:provider-instance', [
            'key' => 'generic', 'provider' => 'generic_provider', 'environment' => 'sandbox',
            'external-account-id' => 'opaque-id', '--account-type' => 'workspace',
        ])->assertSuccessful();
        $this->assertSame('workspace', ProviderInstance::sole()->account_type);
    }

    public function test_rollback_refuses_namespace_collapse_before_changing_schema(): void
    {
        $this->register('client', 'client');
        $this->register('organization', 'sales_organization');
        $migration = require database_path('migrations/2026_09_14_163000_add_account_type_to_provider_instances.php');
        try {
            $migration->down();
            $this->fail('Rollback cannot collapse typed namespaces.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('would collapse', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('provider_instances', 'account_type'));
        $this->assertDatabaseCount('provider_instances', 2);
        $this->register('legacy');
        $this->assertDatabaseCount('provider_instances', 3);
    }
}
