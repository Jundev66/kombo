<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Who took the order out. Without it, `delivery.view_own` existed with no way
 * to know which deliveries were theirs.
 *
 * Id AND copied name: the day a courier leaves, an old order still has to say
 * who took it — that is what they get paid on.
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
            // Serves "what I am carrying" exactly: the query the courier's screen
            // makes on every refresh.
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
