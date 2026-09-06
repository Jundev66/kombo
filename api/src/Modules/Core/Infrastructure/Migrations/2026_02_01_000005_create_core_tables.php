<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Two things that belong to the TENANT, not to the menu: what the dollar is
 * worth and what time the doors open.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('exchange_rates', function (Blueprint $table): void {
            // 18,6 — six decimals. With fewer, converting a large total carries an
            // error visible in the change.
            $table->decimal('rate', 18, 6);

            // Where it came from. It matters for arguing about it: "the owner set it"
            // and "it came from the BCV" are defended differently.
            $table->string('source')->default('custom');

            $table->date('effective_date');
            $table->uuid('created_by')->nullable();

            // One rate per day and source. Correcting today's replaces it rather than
            // stacking versions with no idea which was used.
            TenantSchema::uniquePerTenant($table, ['effective_date', 'source'], 'uq_exchange_rates_day');
            TenantSchema::index($table, ['effective_date'], 'idx_exchange_rates_tenant_date');
        });

        TenantSchema::create('business_hours', function (Blueprint $table): void {
            // 0 = Sunday, like PHP's `date('w')` and like Postgres.
            $table->smallInteger('weekday');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);

            TenantSchema::uniquePerTenant($table, ['weekday'], 'uq_business_hours_weekday');
        });

        // One slot per day on purpose. Places that close at midday exist, but
        // modelling them now would complicate the screen for everybody.
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
        Schema::dropIfExists('exchange_rates');
    }
};
