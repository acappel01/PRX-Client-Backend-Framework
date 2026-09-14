<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_instances', function (Blueprint $table): void {
            // Existing namespaces retain their original generic tenant meaning.
            $table->string('account_type', 64)->default('tenant');
            $table->unique(['provider', 'environment', 'account_type', 'external_account_id'], 'provider_instance_typed_scope_unique');
        });
        Schema::table('provider_instances', function (Blueprint $table): void {
            $table->dropUnique('provider_instance_scope_unique');
        });
    }

    public function down(): void
    {
        // Preflight before any DDL: different account types must not silently
        // collapse into one legacy namespace. Adding the old unique key first
        // also fails safely if a competing write arrives after this check.
        if (DB::table('provider_instances')->select('provider', 'environment', 'external_account_id')
            ->groupBy('provider', 'environment', 'external_account_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Provider account types would collapse. Preserve and reconcile the namespaces before rollback.');
        }
        Schema::table('provider_instances', function (Blueprint $table): void {
            $table->unique(['provider', 'environment', 'external_account_id'], 'provider_instance_scope_unique');
        });
        Schema::table('provider_instances', function (Blueprint $table): void {
            $table->dropUnique('provider_instance_typed_scope_unique');
            $table->dropColumn('account_type');
        });
    }
};
