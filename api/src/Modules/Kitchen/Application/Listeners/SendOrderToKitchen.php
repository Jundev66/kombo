<?php

declare(strict_types=1);

namespace Modules\Kitchen\Application\Listeners;

use App\Models\Kitchen\KitchenTicketModel;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Domain\Events\OrderConfirmed;
use Platform\Capabilities\CurrentCapabilities;

/**
 * Where an order becomes a kitchen ticket.
 *
 * The entire hook-up between orders and the kitchen, one way: `Orders` does not
 * know this exists. The event carries everything needed, so this listener never
 * queries the order tables — that would bring the coupling back in.
 */
final class SendOrderToKitchen
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(OrderConfirmed $event): void
    {
        /*
         * The listener is registered once per process, but the tenant is
         * resolved per request. Without this guard, a tenant without the
         * kitchen switched on would write into a table that does not exist
         * for it.
         */
        if (! $this->capabilities->get()->hasModule('kitchen')) {
            return;
        }

        $this->db->transaction(function () use ($event): void {
            $ticket = KitchenTicketModel::create([
                'order_id' => $event->orderId,
                // THE SAME number as the order: two numbering schemes for one thing is
                // how the wrong plate gets handed over.
                'number' => $event->number,
                'status' => 'pending',
                'service_type' => $event->serviceType,
                'notes' => $event->notes,
                'prep_minutes' => $event->prepMinutes,
                'taken_by_name' => $event->confirmedByName,
                'placed_at' => now(),
            ]);

            foreach ($event->lines as $index => $line) {
                $ticket->items()->create([
                    'product_id' => $line->productId,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    // Already text. The kitchen reads "No onion" while it cooks; looking up
                    // an identifier is not an option.
                    'modifiers' => $line->modifiers,
                    'notes' => $line->notes,
                    'sort_order' => $index,
                ]);
            }
        });
    }
}
