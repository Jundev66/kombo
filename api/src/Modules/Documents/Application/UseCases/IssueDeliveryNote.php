<?php

declare(strict_types=1);

namespace Modules\Documents\Application\UseCases;

use App\Models\Documents\DeliveryNoteModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\DatabaseManager;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;

/**
 * Emitir la nota de entrega de un pedido.
 *
 * Dos cosas que parecen detalles de implementación y son decisiones:
 *
 * **El correlativo se genera bajo cerrojo por negocio y serie.** Dos cajas
 * cobrando a la vez no pueden sacar el mismo número, y detrás está el único
 * `(tenant_id, series, number)` por si algo fallara.
 *
 * **Se guarda un `snapshot` con el documento tal como se imprimió.** Reimprimir
 * la nota de hace tres meses tiene que dar exactamente el mismo papel, aunque
 * el producto se haya renombrado o borrado. Reconstruirla desde las tablas
 * vivas daría otro papel, y el que reclama tiene el original en la mano.
 */
final class IssueDeliveryNote
{
    private const SERIES = 'A';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(OrderModel $order, ?string $customerName = null, ?string $customerTaxId = null): DeliveryNoteModel
    {
        // Una nota por pedido. Si ya la tiene, se devuelve la misma en vez de
        // emitir otra: cobrar dos veces por un doble toque no puede generar
        // dos documentos con dos números distintos.
        $existing = DeliveryNoteModel::where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $order->loadMissing(['items.modifiers', 'payments']);

        $note = DeliveryNoteModel::create([
            'order_id' => $order->id,
            'series' => self::SERIES,
            'number' => $this->nextNumber(self::SERIES),
            'issued_at' => now(),
            'issued_by' => auth()->id(),
            'issued_by_name' => auth()->user()?->name,
            'customer_name' => $customerName ?? $order->customer_name,
            'customer_tax_id' => $customerTaxId,
            'subtotal_cents' => $order->subtotal_cents,
            'total_cents' => $order->total_cents,
            'currency' => $order->currency,
            'exchange_rate' => $order->exchange_rate,
            'snapshot' => $this->snapshot($order),
        ]);

        $this->audit->record(
            action: 'documents.note_issued',
            entityType: 'delivery_note',
            entityId: (string) $note->id,
            after: ['reference' => $note->reference(), 'total_cents' => $order->total_cents],
        );

        return $note;
    }

    /**
     * Anular una nota.
     *
     * **No libera el número.** La nota queda anulada con su motivo y su autor,
     * y el siguiente documento toma el siguiente número. Un correlativo que se
     * reutiliza no sirve para nada: si dos papeles pueden llevar el mismo
     * número, el número deja de identificar a ninguno.
     */
    public function void(string $noteId, string $reason): DeliveryNoteModel
    {
        $note = DeliveryNoteModel::findOrFail($noteId);

        if ($note->isVoided()) {
            return $note;
        }

        $note->update([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => $reason,
        ]);

        $this->audit->record(
            action: 'documents.note_voided',
            entityType: 'delivery_note',
            entityId: $noteId,
            after: ['reference' => $note->reference()],
            reason: $reason,
        );

        return $note;
    }

    private function nextNumber(string $series): int
    {
        // Cerrojo consultivo por negocio y serie: se suelta solo al terminar la
        // transacción, y funciona también con la tabla vacía —justo la primera
        // nota del día, cuando dos cajas arrancan a la vez—.
        $this->db->select('select pg_advisory_xact_lock(hashtext(?))', [
            "notes:{$this->context->id()}:{$series}",
        ]);

        $last = $this->db->table('delivery_notes')
            ->where('tenant_id', $this->context->id())
            ->where('series', $series)
            ->max('number');

        return (int) $last + 1;
    }

    /**
     * El documento, congelado.
     *
     * @return array<string, mixed>
     */
    private function snapshot(OrderModel $order): array
    {
        return [
            // Lo primero, y literal: este papel dice lo que es.
            'title' => 'NOTA DE ENTREGA',
            'disclaimer' => 'No es una factura',

            'orderNumber' => $order->number,
            'serviceType' => $order->service_type->value,
            'issuedAt' => now()->toAtomString(),

            'lines' => $order->items->map(fn ($item): array => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'unitPriceCents' => $item->unit_price_cents,
                'lineTotalCents' => $item->line_total_cents,
                'modifiers' => $item->modifiers->map(fn ($m): array => [
                    'name' => $m->name,
                    'priceDeltaCents' => $m->price_delta_cents,
                ])->all(),
            ])->all(),

            'subtotalCents' => $order->subtotal_cents,
            'deliveryFeeCents' => $order->delivery_fee_cents,
            'totalCents' => $order->total_cents,
            'currency' => $order->currency,
            // La tasa con la que se cobró, congelada: el importe en bolívares
            // de esta nota no puede cambiar mañana.
            'exchangeRate' => $order->exchange_rate === null ? null : (float) $order->exchange_rate,

            'payments' => $order->payments->map(fn ($p): array => [
                'method' => $p->method,
                'amountCents' => $p->amount_cents,
                'reference' => $p->reference,
            ])->all(),
        ];
    }
}
