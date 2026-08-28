<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Hasta cuándo se espera un pago que todavía no llegó.
 *
 * Un pedido de pago móvil nace esperando el comprobante, y el cliente se va a
 * la aplicación del banco. A veces vuelve; a veces se le olvida, o cambia de
 * idea, o no le alcanzó. Sin una fecha de caducidad esos pedidos se acumulan
 * para siempre en el tablero del negocio, que acaba mirándolo con desconfianza
 * porque la mitad de lo que hay no existe.
 *
 * Dos horas: lo que tarda ir al banco, no lo que tarda arrepentirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable();
        });

        // El trabajo diario pregunta «cuáles esperan pago y ya vencieron»: este
        // índice sirve esa consulta exacta, y sólo tiene filas mientras hay
        // algo que esperar.
        Schema::table('orders', function (Blueprint $table): void {
            TenantSchema::index($table, ['expires_at'], 'idx_orders_tenant_expira');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_tenant_expira');
            $table->dropColumn('expires_at');
        });
    }
};
