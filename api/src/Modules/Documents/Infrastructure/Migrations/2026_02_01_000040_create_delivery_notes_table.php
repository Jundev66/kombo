<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Las notas de entrega.
 *
 * **No son documentos fiscales.** El papel lo dice literalmente: «NOTA DE
 * ENTREGA» y debajo «No es una factura». No llevan número de control, no se
 * numeran con rangos de la autoridad y no se imprimen en máquina fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('delivery_notes', function (Blueprint $table): void {
            TenantSchema::references($table, 'order_id', 'orders', onDelete: 'restrict');

            /*
             * Correlativo PROPIO del negocio, por serie.
             *
             * Se genera bajo cerrojo y sin huecos. Anular NO libera el número:
             * la nota queda anulada con su motivo y su autor, y el siguiente
             * documento toma el siguiente. Un correlativo que se reutiliza es
             * un correlativo que no sirve para nada.
             */
            $table->string('series')->default('A');
            $table->bigInteger('number');

            $table->timestampTz('issued_at');
            $table->uuid('issued_by')->nullable();
            $table->string('issued_by_name')->nullable();

            // Opcionales: los escribe el mostrador si el cliente los pide.
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_id')->nullable();

            $table->bigInteger('subtotal_cents');
            $table->bigInteger('total_cents');
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 6)->nullable();

            /*
             * El documento TAL COMO SE IMPRIMIÓ.
             *
             * Líneas, nombres, cantidades, precios, tasa y totales. Reimprimir
             * la nota de hace tres meses tiene que dar exactamente el mismo
             * papel, aunque el producto se haya renombrado o borrado de la
             * carta. Reconstruirla desde las tablas vivas daría otro papel, y
             * el que reclama tiene el original en la mano.
             */
            $table->jsonb('snapshot');

            $table->integer('printed_count')->default(0);

            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->text('void_reason')->nullable();

            // Una nota por pedido, y un número por serie que no se repite.
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
