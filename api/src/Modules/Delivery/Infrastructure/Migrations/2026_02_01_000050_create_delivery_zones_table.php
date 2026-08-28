<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Las zonas de reparto.
 *
 * **Por zona con tarifa fija, no por distancia calculada.** Un mapa y un radio
 * en kilómetros suena más moderno y es peor: aquí el reparto se cobra por
 * barrio —«a Los Palos Grandes son dos dólares»— porque lo que encarece un
 * viaje no son los kilómetros sino subir un cerro, cruzar la autopista o que
 * no haya dónde estacionar. El repartidor ya sabe cuánto cuesta cada sitio; el
 * sistema sólo tiene que dejarlo escrito.
 *
 * Y el cliente elige la suya de una lista, que es una decisión que sabe tomar,
 * en vez de darle permiso de ubicación a una página web.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('delivery_zones', function (Blueprint $table): void {
            $table->string('name');

            // En centavos de USD, como todo el dinero del sistema.
            $table->bigInteger('fee_cents')->default(0);

            /*
             * Cuánto se tarda en llegar, aproximadamente.
             *
             * Se le dice al cliente ANTES de que pida, no después. «Como media
             * hora» evita la mitad de las llamadas preguntando por el pedido, y
             * es la información que más se agradece cuando se tiene hambre.
             */
            $table->integer('estimated_minutes')->nullable();

            // Se apaga, no se borra: una zona borrada dejaría sin explicación
            // los pedidos viejos que se repartieron ahí.
            $table->boolean('is_active')->default(true);

            $table->integer('sort_order')->default(0);

            TenantSchema::uniquePerTenant($table, ['name'], 'uq_delivery_zones_name');
            TenantSchema::index($table, ['is_active', 'sort_order'], 'idx_delivery_zones_activas');
        });

        Schema::table('orders', function (Blueprint $table): void {
            /*
             * La zona a la que fue.
             *
             * Se guarda ADEMÁS de la tarifa ya copiada en `delivery_fee_cents`
             * y del nombre: la tarifa dice cuánto se cobró y no cambia nunca; el
             * nombre deja legible el pedido aunque la zona se borre; el
             * identificador sirve para saber qué barrio pide más. Si mañana
             * sube el precio de la zona, el pedido de ayer sigue diciendo lo
             * que costó.
             */
            TenantSchema::references($table, 'delivery_zone_id', 'delivery_zones', nullable: true, onDelete: 'set null');

            // COPIADO, como todos los nombres que van en un documento.
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
