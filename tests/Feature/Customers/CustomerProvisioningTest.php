<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\EnsureCustomerForPortalAccountAction;
use App\Actions\Customers\MapCustomerToProviderAction;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\ProviderInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function providerInstance(string $key = 'test-sandbox', string $environment = 'sandbox'): ProviderInstance
    {
        return ProviderInstance::create(['key' => $key, 'provider' => 'prescribe_rx',
            'environment' => $environment, 'external_account_id' => $key]);
    }

    public function test_backfill_previews_then_applies_idempotently_without_changing_security(): void
    {
        $account = Patient::factory()->withPrxChart()->create();
        $account->createToken('existing', ['patient:*']);
        $before = $account->fresh()->getRawOriginal();
        $this->artisan('customers:backfill')->expectsOutput('Preview only: 1 accounts; 0 conflicts.')->assertSuccessful();
        $this->assertDatabaseCount('customers', 0);
        $this->artisan('customers:backfill', ['--apply' => true])->assertSuccessful();
        $customer = Customer::sole();
        $customer->update(['first_name' => 'Edited']);
        $this->artisan('customers:backfill', ['--apply' => true])->assertSuccessful();
        $this->assertSame('Edited', Customer::sole()->first_name);
        $this->assertSame($before, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('customer_provider_links', 0);
    }

    public function test_mapping_requires_explicit_accounts_and_preview_rolls_back_links(): void
    {
        $account = Patient::factory()->withPrxChart()->create();
        $instance = $this->providerInstance();
        $this->artisan('customers:backfill', ['--provider-instance' => $instance->key])->assertFailed();
        $options = ['--account' => [$account->id], '--provider-instance' => $instance->key];
        $this->artisan('customers:backfill', $options)->assertSuccessful();
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_provider_links', 0);
        $this->artisan('customers:backfill', $options + ['--apply' => true])->assertSuccessful();
        $this->artisan('customers:backfill', $options + ['--apply' => true])->assertSuccessful();
        $this->assertSame($account->prx_patient_chart_id, Customer::sole()->providerLinks()->sole()->chart_id);
        $this->assertDatabaseCount('customer_provider_links', 1);
    }

    public function test_mapping_is_scoped_and_does_not_change_account_entitlement(): void
    {
        $account = Patient::factory()->create();
        $customer = app(EnsureCustomerForPortalAccountAction::class)->execute($account);
        $before = $account->fresh()->getRawOriginal();
        $map = app(MapCustomerToProviderAction::class);
        $one = $this->providerInstance();
        $two = $this->providerInstance('other-production', 'production');
        $three = $this->providerInstance('other-tenant');
        $link = $map->execute($customer, $one, 'chart', 'patient', 'number');
        $this->assertSame($link->id, $map->execute($customer, $one, 'chart', 'patient', 'number')->id);
        $map->execute(Customer::factory()->create(), $two, 'chart', 'patient', 'number');
        $map->execute(Customer::factory()->create(), $three, 'chart', 'patient', 'number');
        $this->assertDatabaseCount('customer_provider_links', 3);
        $this->assertSame($before, $account->fresh()->getRawOriginal());
        foreach (['chart_id', 'patient_id', 'patient_number'] as $field) {
            $this->assertArrayNotHasKey($field, $link->toArray());
        }
    }

    public function test_mapping_refuses_reassignment_and_conflicting_identifiers(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $instance = $this->providerInstance();
        $map = app(MapCustomerToProviderAction::class);
        $map->execute($customer, $instance, 'chart', 'patient', 'number');
        $map->execute($other, $instance, 'other-chart');
        foreach ([[$other, 'chart', 'patient', 'number'], [$customer, 'new-chart', null, null],
            [$customer, 'chart', 'wrong', 'number'], [$customer, 'chart', 'patient', 'wrong']] as [$owner, $chart, $patient, $number]) {
            try {
                $map->execute($owner, $instance, $chart, $patient, $number);
                $this->fail('Conflicting mapping accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('provider_link', $exception->errors());
            }
        }
        $this->assertDatabaseCount('customer_provider_links', 2);
    }

    public function test_backfill_reports_deleted_customer_and_environment_conflicts_without_partial_account_writes(): void
    {
        $deleted = Patient::factory()->create();
        app(EnsureCustomerForPortalAccountAction::class)->execute($deleted)->delete();
        $account = Patient::factory()->withPrxChart()->create();
        app(EnsureCustomerForPortalAccountAction::class)->execute($account, 'production');
        $instance = $this->providerInstance();
        $this->artisan('customers:backfill', ['--apply' => true, '--account' => [$deleted->id, $account->id],
            '--provider-instance' => $instance->key])->expectsOutput('Applied: 0 accounts; 2 conflicts.')->assertFailed();
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('customer_provider_links', 0);
    }

    public function test_registration_is_idempotent_and_refuses_scope_changes_or_aliases(): void
    {
        $args = ['key' => 'provider-sandbox', 'provider' => 'prescribe_rx', 'environment' => 'sandbox', 'external-account-id' => 'tenant'];
        $this->artisan('customers:provider-instance', $args)->assertSuccessful();
        $this->artisan('customers:provider-instance', $args)->assertSuccessful();
        $this->artisan('customers:provider-instance', array_replace($args, ['environment' => 'production']))->assertFailed();
        $this->artisan('customers:provider-instance', array_replace($args, ['key' => 'alias']))->assertFailed();
        $this->assertDatabaseCount('provider_instances', 1);
        $this->assertSame('sandbox', ProviderInstance::sole()->environment);
    }

    public function test_preview_detects_conflicting_prospective_charts_across_selected_accounts(): void
    {
        $accounts = Patient::factory()->count(2)->create();
        foreach ($accounts as $account) {
            app(EnsureCustomerForPortalAccountAction::class)->execute($account)->update(['prx_patient_chart_id' => 'same-historical-chart']);
        }
        $instance = $this->providerInstance();
        $options = ['--account' => $accounts->modelKeys(), '--provider-instance' => $instance->key];
        $this->artisan('customers:backfill', $options)->expectsOutput('Preview only: 1 accounts; 1 conflicts.')->assertFailed();
        $this->assertDatabaseCount('customer_provider_links', 0);
        $this->artisan('customers:backfill', $options + ['--apply' => true])->expectsOutput('Applied: 1 accounts; 1 conflicts.')->assertFailed();
        $this->assertDatabaseCount('customer_provider_links', 1);
    }

    public function test_opaque_provider_and_chart_identifiers_preserve_case_and_accent_distinctions(): void
    {
        foreach (['Tenant-A', 'tenant-a', 'ténant-a'] as $index => $tenant) {
            $this->artisan('customers:provider-instance', ['key' => 'tenant-'.$index, 'provider' => 'example',
                'environment' => 'sandbox', 'external-account-id' => $tenant])->assertSuccessful();
        }
        $instance = ProviderInstance::first();
        foreach (['Chart-A', 'chart-a', 'chárt-a'] as $chart) {
            app(MapCustomerToProviderAction::class)->execute(Customer::factory()->create(), $instance, $chart);
        }
        $this->assertDatabaseCount('provider_instances', 3);
        $this->assertDatabaseCount('customer_provider_links', 3);
    }

    public function test_provider_namespace_cannot_be_reinterpreted_by_ordinary_model_updates(): void
    {
        $instance = $this->providerInstance();
        $original = $instance->fresh()->getRawOriginal();
        foreach (['key' => 'different', 'provider' => 'different', 'environment' => 'production', 'external_account_id' => 'different'] as $field => $value) {
            try {
                $instance->fresh()->update([$field => $value]);
                $this->fail('Provider namespace was reinterpreted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('provider_instance', $exception->errors());
            }
        }
        $this->assertSame($original, $instance->fresh()->getRawOriginal());
    }

    public function test_backfill_rejects_missing_or_deleted_explicit_accounts(): void
    {
        $account = Patient::factory()->create();
        $account->delete();
        $this->artisan('customers:backfill', ['--account' => [$account->id], '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('customers', 0);
    }
}
