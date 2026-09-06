<?php

declare(strict_types=1);

namespace Modules\Documents\Interfaces\Http\Resources;

use App\Models\Documents\DeliveryNoteModel;

/**
 * The note, as sent to the client to paint or print.
 *
 * The whole `snapshot` is returned — the frozen document — so reprinting an old
 * note cannot give a different paper from the one the customer is holding.
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

            // Verbatim onto the paper: this document says what it is and what it
            // is not.
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
