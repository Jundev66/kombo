<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The delivery zones: by zone with a fixed fee, not by computed distance.
 *
 * A map and a radius sounds more modern and is worse: what makes a trip
 * expensive is not the kilometres but climbing a hill or having nowhere to
 * park. The courier already knows what each place costs.
 *
 * And the customer picks from a list, rather than granting a web page location
 * permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('delivery_zones', function (Blueprint $table): void {
            $table->string('name');

            // In USD cents, like all money in the system.
            $table->bigInteger('fee_cents')->default(0);

            /*
             * Roughly how long it takes to get there. Told to the customer
             * BEFORE they order: "about half an hour" heads off half the calls
             * asking after the order.
             */
            $table->integer('estimated_minutes')->nullable();

            // Switched off, not deleted: a deleted zone would leave old orders
            // delivered there with no explanation.
            $table->boolean('is_active')->default(true);

            $table->integer('sort_order')->default(0);

            TenantSchema::uniquePerTenant($table, ['name'], 'uq_delivery_zones_name');
            TenantSchema::index($table, ['is_active', 'sort_order'], 'idx_delivery_zones_activas');
        });

        Schema::table('orders', function (Blueprint $table): void {
            /*
             * The zone it went to, stored IN ADDITION to the copied fee and
             * name: the fee says what was charged, the name keeps the order
             * readable, and the id tells you which neighbourhood orders most.
             */
            TenantSchema::references($table, 'delivery_zone_id', 'delivery_zones', nullable: true, onDelete: 'set null');

            // COPIED, like every name that goes onto a document.
            $table->string('delivery_zone_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['delivery_zone_id', 'delivery_zone_name']);
        });

        Schema::dropIfExists('delivery_zones');
    }
};
