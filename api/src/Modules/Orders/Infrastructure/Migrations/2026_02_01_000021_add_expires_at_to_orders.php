<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * How long a payment that has not arrived is waited for.
 *
 * The customer goes off to the banking app and sometimes does not come back.
 * Without an expiry those orders pile up forever on the board, which then gets
 * viewed with suspicion because half of it does not exist.
 *
 * Two hours: how long it takes to go to the bank, not to have second thoughts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable();
        });

        // The daily job asks "awaiting payment and already expired": this index
        // serves that query, and only has rows while there is something to wait for.
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
