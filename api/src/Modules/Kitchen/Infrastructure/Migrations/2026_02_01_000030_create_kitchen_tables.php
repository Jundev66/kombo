<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Las comandas.
 *
 * **Tablas propias, NO una vista sobre los pedidos**, y es una decisión con
 * consecuencias: la cocina tiene su propio ciclo de vida. Un pedido cancelado
 * porque el cliente se arrepintió no borra que la comida ya se hizo, y esas
 * dos verdades tienen que poder convivir sin pelearse.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('kitchen_tickets', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'cascade');

            // El MISMO número del pedido: es el que se grita en el mostrador,
            // y tener dos numeraciones distintas para lo mismo es cómo se
            // entrega el plato equivocado.
            $table->bigInteger('number');

            $table->string('status')->default('pending');
            $table->string('service_type')->nullable();

            // La estación: cocina, parrilla, bebidas. Todavía no se usa —hoy
            // todo va a una sola pantalla— pero está desde el principio porque
            // añadirla después obliga a migrar comandas vivas.
            $table->string('station')->nullable();

            $table->string('taken_by_name')->nullable();
            $table->text('notes')->nullable();

            // Cuánto DEBERÍA tardar, según lo que lleva. De aquí sale el
            // semáforo: sin esto, «va tarde» sería una corazonada.
            $table->integer('prep_minutes')->nullable();

            $table->timestampTz('placed_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('served_at')->nullable();

            // Una comanda por pedido. Si el mismo pedido se confirmara dos
            // veces —dos personas pulsando a la vez— la base lo impide en vez
            // de duplicar la comida.
            TenantSchema::uniquePerTenant($table, ['order_id'], 'uq_kitchen_tickets_order');

            // Sirve exacto a la consulta que la pantalla hace cada 5 segundos.
            TenantSchema::index($table, ['status', 'placed_at'], 'idx_kitchen_tickets_tenant_status');
        });

        TenantSchema::create('kitchen_ticket_items', function (Blueprint $table): void {
            TenantSchema::references($table, 'ticket_id', 'kitchen_tickets', onDelete: 'cascade');
            TenantSchema::references($table, 'product_id', 'products', nullable: true, onDelete: 'set null');

            // COPIADO. Una comanda de hace un mes tiene que poder reimprimirse
            // idéntica aunque el producto se haya renombrado o borrado.
            $table->string('name');
            $table->decimal('quantity', 12, 3);

            /*
             * Los agregados YA RESUELTOS EN TEXTO, no como referencias.
             *
             * La cocina lee «Sin cebolla», no un identificador que habría que
             * ir a buscar. Y si mañana se renombra ese modificador, la comanda
             * de hoy sigue diciendo lo que se pidió.
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
