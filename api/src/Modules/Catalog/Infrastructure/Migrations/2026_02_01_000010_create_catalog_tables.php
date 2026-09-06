<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The menu: what this tenant sells.
 *
 * Loaded by the module's manifest rather than `database/migrations/`, so
 * removing the module takes its schema with it.
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

            // USD cents. The bolívar is computed with the rate of the day and frozen
            // into each document; storing it here would store a value with an expiry.
            $table->bigInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // When the price last changed: what the owner scans for products that have
            // gone months without review.
            $table->timestampTz('price_updated_at')->nullable();

            // How long it takes to come out. The kitchen traffic light reads this.
            $table->integer('prep_minutes')->nullable();

            $table->boolean('is_active')->default(true);

            // Most dishes do NOT track stock: they are made to order. Switched on for
            // countable things, and only then does `stock_qty` mean anything.
            $table->boolean('track_stock')->default(false);
            $table->integer('stock_qty')->nullable();

            $table->integer('sort_order')->default(0);

            TenantSchema::index($table, ['category_id', 'sort_order'], 'idx_products_tenant_category');
            TenantSchema::index($table, ['is_active'], 'idx_products_tenant_active');
        });

        // A group is a QUESTION put to whoever orders, and min/max say which kind:
        //   (0, N) optional extras · (1, 1) pick one, required · (0, 1) exclusive
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

            // Can be NEGATIVE: "no cheese" sometimes takes money off.
            $table->bigInteger('price_delta_cents')->default(0);

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            TenantSchema::index($table, ['group_id', 'sort_order'], 'idx_modifiers_tenant_group');
        });

        // Which questions apply to which product.
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
