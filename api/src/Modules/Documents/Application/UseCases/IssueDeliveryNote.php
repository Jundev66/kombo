<?php

declare(strict_types=1);

namespace Modules\Documents\Application\UseCases;

use App\Models\Documents\DeliveryNoteModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\DatabaseManager;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;

/**
 * Issuing an order's delivery note.
 *
 * The sequence number is generated under a lock, per tenant and series, with
 * the unique `(tenant_id, series, number)` behind it.
 *
 * A `snapshot` stores the document exactly as printed: reprinting a note from
 * three months ago has to produce the same piece of paper, and whoever is
 * complaining has the original in their hand.
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
        // One note per order. If it already has one the same is returned: a double
        // tap cannot produce two documents with two different numbers.
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
     * Voiding a note. It does NOT release the number.
     *
     * The note stays voided with its reason and author, and the next document
     * takes the next number. If two pieces of paper can carry the same number,
     * the number identifies neither.
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
        // Advisory lock per tenant and series: released when the transaction ends,
        // and it works on an empty table too — the first note of the day, when two
        // tills start at once.
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
     * The document, frozen.
     *
     * @return array<string, mixed>
     */
    private function snapshot(OrderModel $order): array
    {
        return [
            // First, and verbatim: this piece of paper says what it is.
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
            // The rate it was charged at, frozen: this note's bolívar amount cannot
            // change tomorrow.
            'exchangeRate' => $order->exchange_rate === null ? null : (float) $order->exchange_rate,

            'payments' => $order->payments->map(fn ($p): array => [
                'method' => $p->method,
                'amountCents' => $p->amount_cents,
                'reference' => $p->reference,
            ])->all(),
        ];
    }
}
