<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Los pedidos: lo que se vende, cómo se paga y por dónde va.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('orders', function (Blueprint $table): void {
            // Correlativo del negocio. Es el número que se grita en el
            // mostrador y el que aparece en la comanda, así que es corto y no
            // el uuid.
            $table->bigInteger('number');

            /*
             * El token con el que el CLIENTE sigue su pedido, sin cuenta.
             *
             * Es un secreto de capacidad: quien lo tiene, ve ese pedido y
             * ninguno más. Hace falta porque la pantalla de pago tiene que
             * sobrevivir a que cierre el navegador para ir a la aplicación del
             * banco y vuelva.
             */
            $table->string('public_token', 32);

            $table->string('status')->default('placed');
            $table->string('service_type')->default('takeaway');

            // Por dónde entró: el portal, un bot, o el mostrador. Sirve para
            // saber qué canal trae negocio, que es una de las primeras cosas
            // que un dueño quiere saber.
            $table->string('channel')->default('counter');

            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('delivery_address')->nullable();

            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('delivery_fee_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // La tasa CONGELADA. Sin esto, un pedido de marzo cambiaría de
            // importe en bolívares cada mañana.
            $table->decimal('exchange_rate', 18, 6)->nullable();

            $table->bigInteger('paid_cents')->default(0);
            $table->string('payment_status')->default('unpaid');

            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            /*
             * Bloqueo optimista DE VERDAD.
             *
             * La caja y la pantalla de cocina tocan el mismo pedido a la vez.
             * Sin esto, quien guarda segundo pisa lo que hizo el primero y
             * nadie se entera. El UPDATE lleva `where state_version = ?`: si no
             * afecta ninguna fila, es que alguien se adelantó.
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

            // Sirve exacto al tablero del panel y a la pantalla de cocina, que
            // preguntan «los abiertos, del más viejo al más nuevo».
            TenantSchema::index($table, ['status', 'placed_at'], 'idx_orders_tenant_status_placed');
        });

        TenantSchema::create('order_items', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'cascade');

            // El producto puede desaparecer de la carta; el pedido no.
            TenantSchema::references($table, 'product_id', 'products', nullable: true, onDelete: 'set null');

            // COPIADOS, no referenciados. Un ticket de hace seis meses debe
            // decir lo que decía cuando se imprimió.
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
         * VARIAS filas por pedido, y ése es todo el punto.
         *
         * Aquí se cobra mezclado: tres dólares en efectivo y el resto en
         * bolívares por pago móvil. Con una sola columna `payment_method` eso
         * no se puede representar, y el cajero acaba anotando la mitad en el
         * campo de observaciones.
         */
        TenantSchema::create('order_payments', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'cascade');

            $table->string('method');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');

            // La tasa de ESE pago. Si el cliente paga en dos veces y la tasa
            // cambió entre medias, cada pago vale lo que valía cuando se hizo.
            $table->decimal('exchange_rate', 18, 6)->nullable();

            $table->string('reference')->nullable();
            $table->string('receipt_url')->nullable();

            // El pago móvil se CONFIRMA a mano: el dueño mira el comprobante y
            // dice que sí. No hay API bancaria que preguntar.
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
