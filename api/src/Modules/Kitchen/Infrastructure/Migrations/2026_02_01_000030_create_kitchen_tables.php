<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The kitchen tickets. Tables of their own, NOT a view over orders.
 *
 * The kitchen has its own lifecycle: an order cancelled because the customer
 * changed their mind does not erase that the food was already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('kitchen_tickets', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'cascade');

            // THE SAME number as the order: it is the one shouted across the counter,
            // and two numbering schemes is how the wrong plate gets handed over.
            $table->bigInteger('number');

            $table->string('status')->default('pending');
            $table->string('service_type')->nullable();

            // The station: kitchen, grill, drinks. Not used yet, but here from the
            // start because adding it later means migrating live tickets.
            $table->string('station')->nullable();

            $table->string('taken_by_name')->nullable();
            $table->text('notes')->nullable();

            // How long it SHOULD take, given what is on it. The traffic light reads
            // this; without it, "running late" is a hunch.
            $table->integer('prep_minutes')->nullable();

            $table->timestampTz('placed_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('served_at')->nullable();

            // One ticket per order: if the same order were confirmed twice, the
            // database stops it rather than duplicating the food.
            TenantSchema::uniquePerTenant($table, ['order_id'], 'uq_kitchen_tickets_order');

            // Serves exactly the query the screen makes every 5 seconds.
            TenantSchema::index($table, ['status', 'placed_at'], 'idx_kitchen_tickets_tenant_status');
        });

        TenantSchema::create('kitchen_ticket_items', function (Blueprint $table): void {
            TenantSchema::references($table, 'ticket_id', 'kitchen_tickets', onDelete: 'cascade');
            TenantSchema::references($table, 'product_id', 'products', nullable: true, onDelete: 'set null');

            // COPIED. A ticket from a month ago reprints identically even if the
            // product has been renamed or deleted.
            $table->string('name');
            $table->decimal('quantity', 12, 3);

            /*
             * The add-ons ALREADY RESOLVED INTO TEXT. The kitchen reads "No
             * onion", not an identifier to look up, and renaming the modifier
             * tomorrow does not change what today's ticket says.
             */
            $table->jsonb('modifiers')->default('[]');

            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            TenantSchema::index($table, ['ticket_id', 'sort_order'], 'idx_kitchen_items_tenant_ticket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');
    }
};
