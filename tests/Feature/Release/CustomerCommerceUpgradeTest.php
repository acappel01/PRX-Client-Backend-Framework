<?php

namespace Tests\Feature\Release;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic upgrade evidence only; never connects to or restores a served database. */
class CustomerCommerceUpgradeTest extends TestCase
{
    public function test_populated_pre_commerce_schema_upgrades_without_inferred_ownership_or_replayed_migrations(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $connection = 'commerce_release_rehearsal';
        config()->set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge($connection);
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection($connection);

        try {
            $this->assertSame('sqlite', DB::connection()->getDriverName());
            $this->assertSame(':memory:', DB::connection()->getDatabaseName());
            $migrator->getRepository()->createRepository();
            $paths = array_merge($migrator->paths(), [database_path('migrations')]);
            $files = $migrator->getMigrationFiles($paths);
            // The served 9909105 baseline predates the first commerce migration.
            // Include registered vendor/settings paths, not just app migrations.
            $baseline = array_filter($files, fn (string $path, string $name): bool => strcmp($name, '2026_09_14_124026_create_customer_commerce_foundation_tables') < 0,
                ARRAY_FILTER_USE_BOTH);
            $migrator->run(array_values($baseline));
            $this->assertFalse(Schema::hasTable('customers'));
            $this->assertFalse(Schema::hasColumn('leads', 'customer_id'));

            $leadId = DB::table('leads')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'first_name' => 'Synthetic',
                'last_name' => 'Upgrade',
                'email' => 'upgrade@example.invalid',
                'email_consent' => false,
                'sms_consent' => false,
                'created_at' => '2026-09-01 12:00:00',
                'updated_at' => '2026-09-01 12:00:00',
            ]);
            $orderId = DB::table('orders')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'prescribe_rx_order_id' => 'synthetic-upgrade-order',
                'currency' => 'USD',
                'total_amount' => '25.00',
                'created_at' => '2026-09-01 12:00:00',
                'updated_at' => '2026-09-01 12:00:00',
            ]);
            $beforeLead = (array) DB::table('leads')->find($leadId);
            $beforeOrder = (array) DB::table('orders')->find($orderId);
            $baselineLedger = DB::table('migrations')->pluck('batch', 'migration')->all();

            $migrator->run($paths);
            $afterLead = (array) DB::table('leads')->find($leadId);
            $afterOrder = (array) DB::table('orders')->find($orderId);
            $this->assertSame($beforeLead, array_intersect_key($afterLead, $beforeLead));
            $this->assertSame($beforeOrder, array_intersect_key($afterOrder, $beforeOrder));
            $this->assertNull($afterLead['customer_id']);
            $this->assertNull($afterOrder['customer_id']);
            foreach (['customers', 'customer_provider_links', 'canonical_events', 'checkout_attempts',
                'lead_submissions', 'checkout_reconciliation_audits', 'payment_intents', 'payment_operations',
                'payment_outcome_observations', 'attribution_touchpoints', 'canonical_deliveries',
                'canonical_delivery_evaluations', 'gateway_account_bindings', 'email_suppression_observations', 'payment_operation_references',
                'authorize_net_receivers', 'gateway_notification_inboxes', 'gateway_notification_conflicts',
                'payment_dispatch_preparations', 'payment_association_scopes', 'payment_transaction_associations'] as $table) {
                $this->assertTrue(Schema::hasTable($table), $table);
                $this->assertSame(0, DB::table($table)->count(), "Migration must not import or produce {$table}");
            }
            $setting = DB::table('settings')->where('group', 'integrations')
                ->where('name', 'prescribe_rx_provider_instance_key')->first();
            $this->assertNotNull($setting);
            $this->assertNull(json_decode($setting->payload, true));
            $upgradedLedger = DB::table('migrations')->pluck('batch', 'migration')->all();
            $this->assertSame($baselineLedger, array_intersect_key($upgradedLedger, $baselineLedger));
            $this->assertCount(count($files), $upgradedLedger);

            $migrator->run($paths);
            $this->assertSame($upgradedLedger, DB::table('migrations')->pluck('batch', 'migration')->all());
            $this->assertSame($afterLead, (array) DB::table('leads')->find($leadId));
            $this->assertSame($afterOrder, (array) DB::table('orders')->find($orderId));
            Http::assertNothingSent();
        } finally {
            $migrator->setConnection($originalConnection);
            DB::purge($connection);
        }
    }
}
