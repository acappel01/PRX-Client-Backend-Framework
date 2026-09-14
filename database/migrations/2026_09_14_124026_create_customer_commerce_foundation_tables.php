<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Commerce records do not grant a login or prove a clinical chart claim.
            $table->foreignId('portal_account_id')->nullable()->unique()->constrained('patients')->nullOnDelete();
            foreach (['first_name', 'last_name', 'email', 'phone', 'date_of_birth'] as $field) {
                $table->text($field)->nullable();
            }
            $table->string('provider_environment', 16)->nullable();
            foreach (['prx_patient_chart_id', 'prx_patient_id', 'prx_patient_number'] as $field) {
                $table->string($field, 64)->nullable()->index();
            }
            // These references are not globally unique across configured provider accounts.
            // No import or provider identity resolution is enabled by this foundation.
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->text('address');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'kind', 'is_default']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
