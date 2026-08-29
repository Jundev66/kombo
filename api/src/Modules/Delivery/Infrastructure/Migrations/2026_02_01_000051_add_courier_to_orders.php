<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Quién se llevó el pedido.
 *
 * Sin esto, «mis entregas» no significaba nada: el permiso `delivery.view_own`
 * existía y no había forma de saber cuáles eran las suyas.
 *
 * Va el identificador **y el nombre copiado**, como todo lo que se escribe en
 * un documento: el día que un repartidor se dé de baja, el pedido de hace tres
 * meses tiene que seguir diciendo quién lo llevó — es con eso con lo que se le
 * paga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            TenantSchema::references($table, 'courier_id', 'users', nullable: true, onDelete: 'set null');
            $table->string('courier_name')->nullable();
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Sirve exacto a «lo que llevo yo»: la consulta que hace la
            // pantalla del repartidor cada vez que se refresca.
            TenantSchema::index($table, ['courier_id', 'status'], 'idx_orders_repartidor');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_repartidor');
            $table->dropColumn(['courier_id', 'courier_name']);
        });
    }
};
