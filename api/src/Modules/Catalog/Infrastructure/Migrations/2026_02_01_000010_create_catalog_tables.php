<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * La carta: qué vende este negocio.
 *
 * Vive dentro del módulo y la carga su manifiesto, no `database/migrations/`.
 * Así, quitar el módulo del sistema se lleva su esquema con él.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('categories', function (Blueprint $table): void {
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            TenantSchema::index($table, ['sort_order'], 'idx_categories_tenant_sort');
        });

        TenantSchema::create('products', function (Blueprint $table): void {
            TenantSchema::references($table, 'category_id', 'categories', nullable: true, onDelete: 'set null');

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('photo_url')->nullable();

            // Centavos de USD. El bolívar se calcula con la tasa del día y se
            // congela en cada documento; guardarlo aquí sería guardar un valor
            // que caduca.
            $table->bigInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // Cuándo cambió el precio por última vez. Sirve para que el dueño
            // vea de un vistazo qué lleva meses sin revisar, que en un país con
            // inflación es la diferencia entre ganar y regalar.
            $table->timestampTz('price_updated_at')->nullable();

            // Cuánto tarda en salir. De aquí sale el semáforo de la pantalla de
            // cocina: sin esto, «va tarde» sería una corazonada.
            $table->integer('prep_minutes')->nullable();

            $table->boolean('is_active')->default(true);

            // La mayoría de los platos NO llevan control de existencias: se
            // hacen al momento. Se activa para lo contado —las diez tortas del
            // día, las cervezas— y sólo entonces `stock_qty` significa algo.
            $table->boolean('track_stock')->default(false);
            $table->integer('stock_qty')->nullable();

            $table->integer('sort_order')->default(0);

            TenantSchema::index($table, ['category_id', 'sort_order'], 'idx_products_tenant_category');
            TenantSchema::index($table, ['is_active'], 'idx_products_tenant_active');
        });

        // Un grupo es una PREGUNTA que se le hace a quien pide, y min/max dicen
        // de qué tipo:
        //   (0, N)  extras opcionales      «¿algo más?»
        //   (1, 1)  elegir uno, obligatorio «¿término de la carne?»
        //   (0, 1)  opcional excluyente     «¿alguna salsa?»
        TenantSchema::create('modifier_groups', function (Blueprint $table): void {
            $table->string('name');
            $table->smallInteger('min_choices')->default(0);
            $table->smallInteger('max_choices')->default(1);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            TenantSchema::index($table, ['sort_order'], 'idx_modifier_groups_tenant_sort');
        });

        TenantSchema::create('modifiers', function (Blueprint $table): void {
            TenantSchema::references($table, 'group_id', 'modifier_groups', onDelete: 'cascade');

            $table->string('name');

            // Puede ser NEGATIVO: «sin queso» a veces descuenta.
            $table->bigInteger('price_delta_cents')->default(0);

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            TenantSchema::index($table, ['group_id', 'sort_order'], 'idx_modifiers_tenant_group');
        });

        // Qué preguntas aplican a qué producto.
        TenantSchema::create('product_modifier_groups', function (Blueprint $table): void {
            TenantSchema::references($table, 'product_id', 'products', onDelete: 'cascade');
            TenantSchema::references($table, 'group_id', 'modifier_groups', onDelete: 'cascade');

            $table->integer('sort_order')->default(0);

            TenantSchema::uniquePerTenant($table, ['product_id', 'group_id'], 'uq_product_modifier_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_modifier_groups');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
