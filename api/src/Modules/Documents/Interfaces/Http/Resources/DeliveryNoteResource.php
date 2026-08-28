<?php

declare(strict_types=1);

namespace Modules\Documents\Interfaces\Http\Resources;

use App\Models\Documents\DeliveryNoteModel;

/**
 * La nota, tal como se manda al cliente para pintarla o imprimirla.
 *
 * Se devuelve el `snapshot` entero: es el documento congelado, y la pantalla
 * pinta eso y no una reconstrucción. Si reconstruyera desde las tablas vivas,
 * reimprimir una nota vieja daría otro papel del que el cliente tiene en la
 * mano.
 */
final class DeliveryNoteResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(DeliveryNoteModel $note): array
    {
        return [
            'id' => $note->id,
            'orderId' => $note->order_id,
            'reference' => $note->reference(),
            'series' => $note->series,
            'number' => $note->number,

            // Literales, y van en el papel tal cual: este documento dice lo
            // que es y lo que no es.
            'title' => 'NOTA DE ENTREGA',
            'disclaimer' => 'No es una factura',

            'issuedAt' => $note->issued_at?->toAtomString(),
            'issuedByName' => $note->issued_by_name,
            'customerName' => $note->customer_name,
            'customerTaxId' => $note->customer_tax_id,

            'totalCents' => $note->total_cents,
            'currency' => $note->currency,
            'exchangeRate' => $note->exchange_rate === null ? null : (float) $note->exchange_rate,

            'isVoided' => $note->isVoided(),
            'voidReason' => $note->void_reason,
            'printedCount' => $note->printed_count,

            'snapshot' => $note->snapshot,
        ];
    }
}
