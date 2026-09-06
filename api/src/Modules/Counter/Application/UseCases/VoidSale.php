<?php

declare(strict_types=1);

namespace Modules\Counter\Application\UseCases;

use App\Models\Documents\DeliveryNoteModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\DatabaseManager;
use Modules\Documents\Application\UseCases\IssueDeliveryNote;
use Modules\Orders\Application\UseCases\CancelOrder;
use Platform\Audit\AuthorizedBy;

/**
 * Voiding a counter sale. One operation, not two.
 *
 * Voiding the note alone leaves a paid sale with no paperwork; cancelling the
 * order alone leaves paperwork backing nothing. With one note per order and no
 * reissue, voiding the document is necessarily voiding the sale.
 *
 * It does not refund: what is handed back to the customer is not something the
 * system knows about, and faking a reversal would invent a cash movement.
 */
final class VoidSale
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CancelOrder $cancelOrder,
        private readonly IssueDeliveryNote $notes,
    ) {}

    /**
     * @return array{order: OrderModel, note: DeliveryNoteModel|null}
     */
    public function execute(string $orderId, string $reason, ?AuthorizedBy $authorizedBy = null): array
    {
        return $this->db->transaction(function () use ($orderId, $reason, $authorizedBy): array {
            // Fetched BEFORE cancelling: afterwards, whoever looks at this note has to
            // be able to see why.
            $note = DeliveryNoteModel::where('order_id', $orderId)->first();

            // Cancels first: an already-delivered order is refused by the domain and
            // the note is never touched.
            $order = $this->cancelOrder->execute($orderId, $reason, $authorizedBy);

            if ($note !== null) {
                // The number is not released. The next sale takes the next one.
                $note = $this->notes->void((string) $note->id, $reason);
            }

            return ['order' => $order, 'note' => $note];
        });
    }
}
