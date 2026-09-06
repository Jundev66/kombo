<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The index that serves the reports, and only that one.
 *
 * Every query here asks the same thing: "this business's orders confirmed
 * between these two dates". Without this index, PostgreSQL walks the entire
 * orders table — a year of it — every time the owner opens the screen on their
 * phone.
 *
 * The existing `(tenant_id, status, placed_at)` is no use here: it orders by
 * status first, and these queries do not filter on a single one.
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
