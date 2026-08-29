<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Los clientes del negocio, identificados por su teléfono.
 *
 * **No lleva clave foránea desde `orders`**, y es deliberado. Los pedidos ya
 * guardan el nombre y el teléfono copiados —como el nombre del producto— así
 * que un pedido de hace seis meses se lee entero aunque el cliente se borre. La
 * ficha se une por teléfono al consultarla.
 *
 * La ventaja de no tener la columna: `Orders` no sabe que este módulo existe, y
 * apagar los clientes no deja pedidos apuntando a una tabla que ya no se usa.
 *
 * El teléfono va CIFRADO, como en `users`: es un dato personal, y una lista de
 * teléfonos filtrada es exactamente lo que un competidor querría.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('customers', function (Blueprint $table): void {
            /*
             * El teléfono cifrado NO se puede indexar ni buscar por igualdad
             * —el cifrado de Laravel no es determinista—, así que al lado va un
             * hash: sirve para encontrar al cliente sin poder leer el número.
             */
            $table->text('phone');
            $table->string('phone_hash', 64);

            $table->string('name')->nullable();
            $table->text('notes')->nullable();

            // Se mantienen solos, por evento. Sirven para el «quién compra
            // más» sin tener que sumar todos los pedidos cada vez.
            $table->integer('orders_count')->default(0);
            $table->bigInteger('spent_cents')->default(0);
            $table->timestampTz('last_order_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['phone_hash'], 'uq_customers_phone');
            TenantSchema::index($table, ['last_order_at'], 'idx_customers_recientes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
