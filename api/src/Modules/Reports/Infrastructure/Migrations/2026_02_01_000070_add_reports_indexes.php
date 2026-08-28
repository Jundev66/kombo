<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * El índice que sirve a los reportes, y sólo ése.
 *
 * Todas las consultas de aquí preguntan lo mismo: «los pedidos de este negocio
 * que se confirmaron entre estas dos fechas». Sin este índice, PostgreSQL
 * recorre la tabla entera de pedidos —la de un año— cada vez que el dueño abre
 * la pantalla desde el teléfono.
 *
 * El de `(tenant_id, status, placed_at)` que ya existe no sirve aquí: ordena
 * primero por estado, y estas consultas no filtran por uno solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            TenantSchema::index($table, ['confirmed_at'], 'idx_orders_tenant_confirmado');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_tenant_confirmado');
        });
    }
};
