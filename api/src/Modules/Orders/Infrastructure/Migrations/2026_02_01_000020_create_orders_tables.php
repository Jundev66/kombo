<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The orders: what is sold, how it is paid for and where it has got to.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('orders', function (Blueprint $table): void {
            // The tenant's sequence number: the one shouted across the counter and
            // printed on the ticket, so it is short and not the uuid.
            $table->bigInteger('number');

            /*
             * The token the CUSTOMER follows their order with, without an
             * account. A capability secret: whoever holds it sees that order
             * and no other. The payment screen has to survive the browser
             * closing to visit the banking app and coming back.
             */
            $table->string('public_token', 32);

            $table->string('status')->default('placed');
            $table->string('service_type')->default('takeaway');

            // Where it came in: portal, bot, or counter. Which channel brings business
            // is one of the first things an owner wants to know.
            $table->string('channel')->default('counter');

            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('delivery_address')->nullable();

            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('delivery_fee_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // The FROZEN rate, or a March order's bolívar amount would change every
            // morning.
            $table->decimal('exchange_rate', 18, 6)->nullable();

            $table->bigInteger('paid_cents')->default(0);
            $table->string('payment_status')->default('unpaid');

            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            /*
             * Real optimistic locking. The till and the kitchen screen touch
             * the same order at once; without this, whoever saves second
             * overwrites the first and nobody finds out.
             */
            $table->integer('state_version')->default(0);

            $table->timestampTz('placed_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('preparing_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('out_for_delivery_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->uuid('created_by')->nullable();

            TenantSchema::uniquePerTenant($table, ['number'], 'uq_orders_tenant_number');
            TenantSchema::uniquePerTenant($table, ['public_token'], 'uq_orders_tenant_token');

            // Serves exactly the dashboard board and the kitchen screen: "the open
            // ones, oldest to newest".
            TenantSchema::index($table, ['status', 'placed_at'], 'idx_orders_tenant_status_placed');
        });

        TenantSchema::create('order_items', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'cascade');

            // The product may disappear from the menu; the order may not.
            TenantSchema::references($table, 'product_id', 'products', nullable: true, onDelete: 'set null');

            // COPIED, not referenced. A ticket from six months ago must say what it
            // said when it was printed.
            $table->string('product_name');
            $table->bigInteger('unit_price_cents');

            $table->integer('quantity');
            $table->bigInteger('modifiers_total_cents')->default(0);
            $table->bigInteger('line_total_cents');
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            TenantSchema::index($table, ['order_id', 'sort_order'], 'idx_order_items_tenant_order');
        });

        TenantSchema::create('order_item_modifiers', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_item_id', 'order_items', onDelete: 'cascade');
            TenantSchema::references($table, 'modifier_id', 'modifiers', nullable: true, onDelete: 'set null');

            $table->string('name');
            $table->bigInteger('price_delta_cents')->default(0);
            $table->integer('sort_order')->default(0);

            TenantSchema::index($table, ['order_item_id'], 'idx_order_item_modifiers_tenant_item');
        });

        /*
         * SEVERAL rows per order, and that is the point: people pay in a mix —
         * some cash, the rest by mobile transfer. One `payment_method` column
         * cannot represent that, and the cashier ends up using the notes field.
         */
        TenantSchema::create('order_payments', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'cascade');

            $table->string('method');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');

            // THAT payment's rate: paying in two goes across a rate change means each
            // payment is worth what it was worth then.
            $table->decimal('exchange_rate', 18, 6)->nullable();

            $table->string('reference')->nullable();
            $table->string('receipt_url')->nullable();

            // Mobile payment is confirmed by hand: the owner looks at the receipt and
            // says yes. There is no banking API to ask.
            $table->string('status')->default('pending_review');
            $table->uuid('confirmed_by')->nullable();
            $table->timestampTz('confirmed_at')->nullable();

            $table->uuid('created_by')->nullable();

            TenantSchema::index($table, ['order_id'], 'idx_order_payments_tenant_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
