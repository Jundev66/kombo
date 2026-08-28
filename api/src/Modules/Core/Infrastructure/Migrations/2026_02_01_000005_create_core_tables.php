<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Dos cosas que son del NEGOCIO, no de la carta: a cuánto está el dólar y a
 * qué hora se abre.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('exchange_rates', function (Blueprint $table): void {
            // 18,6 — seis decimales. Con menos, convertir un total grande
            // arrastra un error visible en el vuelto.
            $table->decimal('rate', 18, 6);

            // De dónde salió. Importa para poder discutirla: «la puso el
            // dueño» y «vino del BCV» se defienden distinto ante un cliente.
            $table->string('source')->default('custom');

            $table->date('effective_date');
            $table->uuid('created_by')->nullable();

            // Una tasa por día y origen. Corregir la del día es reemplazarla,
            // no acumular tres versiones y no saber cuál se usó.
            TenantSchema::uniquePerTenant($table, ['effective_date', 'source'], 'uq_exchange_rates_day');
            TenantSchema::index($table, ['effective_date'], 'idx_exchange_rates_tenant_date');
        });

        TenantSchema::create('business_hours', function (Blueprint $table): void {
            // 0 = domingo, como `date('w')` de PHP y como Postgres.
            $table->smallInteger('weekday');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);

            TenantSchema::uniquePerTenant($table, ['weekday'], 'uq_business_hours_weekday');
        });

        // Un solo tramo por día a propósito. Los locales con cierre a mediodía
        // existen, pero son la excepción y modelarlos ahora complicaría la
        // pantalla para todos. Cuando lo pida un cliente real, se añade una
        // tabla de tramos — no antes.
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
        Schema::dropIfExists('exchange_rates');
    }
};
