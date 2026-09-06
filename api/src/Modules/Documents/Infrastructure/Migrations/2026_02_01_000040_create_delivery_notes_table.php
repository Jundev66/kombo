<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The delivery notes. They are NOT fiscal documents.
 *
 * The paper says so literally: "NOTA DE ENTREGA" and below it "No es una
 * factura". No control number, no authority range, no fiscal printer.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('delivery_notes', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'restrict');

            /*
             * The tenant's OWN sequence, per series, generated under a lock and
             * with no gaps. Voiding does not release the number: a sequence
             * that gets reused is good for nothing.
             */
            $table->string('series')->default('A');
            $table->bigInteger('number');

            $table->timestampTz('issued_at');
            $table->uuid('issued_by')->nullable();
            $table->string('issued_by_name')->nullable();

            // Optional: the counter fills them in if the customer asks.
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_id')->nullable();

            $table->bigInteger('subtotal_cents');
            $table->bigInteger('total_cents');
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 6)->nullable();

            /*
             * The document EXACTLY AS PRINTED — lines, names, quantities,
             * prices, rate and totals. Rebuilding it from the live tables would
             * give a different paper from the one the customer is holding.
             */
            $table->jsonb('snapshot');

            $table->integer('printed_count')->default(0);

            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->text('void_reason')->nullable();

            // One note per order, and one number per series that never repeats.
            TenantSchema::uniquePerTenant($table, ['order_id'], 'uq_delivery_notes_order');
            TenantSchema::uniquePerTenant($table, ['series', 'number'], 'uq_delivery_notes_number');
            TenantSchema::index($table, ['issued_at'], 'idx_delivery_notes_tenant_issued');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
