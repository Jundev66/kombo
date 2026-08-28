<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los planes. Tabla de PLATAFORMA: sin `tenant_id` y sin RLS.
 *
 * La regla de negocio está embebida en qué columnas hay y cuáles NO:
 * **se cobra por tamaño, no por funcionalidad básica**. Un negocio pequeño no
 * debe quedarse sin saber cuánto vendió porque no le alcanza. Lo que separa
 * los planes es cuántos usuarios, cuántos productos y cuántos pedidos al mes.
 *
 * Por eso no hay banderas del tipo `tiene_reportes`. Los módulos que incluye
 * cada plan sí van aparte, en `plan_modules`, porque ahí lo que se compra es
 * una capacidad nueva (la caja, los canales), no el sistema básico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);

            $table->bigInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // NULL = ilimitado. Nunca cero: cero significaría "ninguno", que
            // es una respuesta distinta y mucho peor de depurar.
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->integer('max_orders_month')->nullable();

            $table->integer('trial_days')->nullable();
            $table->boolean('is_public')->default(true);

            $table->timestampsTz();
        });

        Schema::create('plan_modules', function (Blueprint $table): void {
            $table->string('plan_code');
            $table->string('module_code');

            $table->primary(['plan_code', 'module_code']);
            $table->foreign('plan_code')->references('code')->on('plans')->cascadeOnDelete();
        });

        // `module_code` es texto libre y no una clave foránea a propósito: no
        // existe una tabla `modules`. Qué módulos hay lo dice el código
        // (config/modules.php), no una fila que alguien pueda borrar.
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plans');
    }
};
