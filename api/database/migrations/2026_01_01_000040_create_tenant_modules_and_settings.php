<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Qué módulos tiene encendidos un negocio, y cómo los quiere.
 *
 * Estas dos tablas son lo que hace que encender la caja o los canales sea
 * **una fila, sin desplegar nada**. El plan pone el techo; estas dos dicen qué
 * está encendido dentro de ese techo y cómo se comporta.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('tenant_modules', function (Blueprint $table): void {
            $table->string('module_code');
            $table->boolean('enabled')->default(true);
            $table->uuid('enabled_by')->nullable();
            $table->timestampTz('enabled_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['module_code'], 'uq_tenant_modules_code');
        });

        TenantSchema::create('tenant_settings', function (Blueprint $table): void {
            // Clave calificada: `orders.auto_confirm`, `kitchen.stale_minutes`.
            $table->string('key');

            // SIEMPRE texto. El tipo lo declara el manifiesto del módulo y lo
            // castea `Setting::cast()`. Guardar el tipo aquí sería tenerlo en
            // dos sitios, y el día que discrepen gana el equivocado.
            $table->text('value');

            $table->uuid('updated_by')->nullable();

            TenantSchema::uniquePerTenant($table, ['key'], 'uq_tenant_settings_key');
        });

        // Los valores POR DEFECTO no se guardan aquí: viven en `settings()` del
        // manifiesto. Esta tabla sólo almacena lo que el negocio cambió.
        //
        // Es lo que permite añadir una opción nueva sin tocar una sola fila, y
        // que cambiar el valor por defecto para todo el mundo sea editar una
        // línea de código en vez de una migración de datos.
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenant_modules');
    }
};
