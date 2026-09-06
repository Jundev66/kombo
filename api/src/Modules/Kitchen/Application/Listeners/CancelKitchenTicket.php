<?php

declare(strict_types=1);

namespace Modules\Kitchen\Application\Listeners;

use App\Models\Kitchen\KitchenTicketModel;
use Modules\Kitchen\Domain\ValueObjects\TicketStatus;
use Modules\Orders\Domain\Events\OrderCancelled;
use Platform\Capabilities\CurrentCapabilities;

/**
 * Takes off the board what no longer needs cooking.
 *
 * The ticket is not deleted, only marked `cancelled` with its timestamp: the
 * food having been started is a fact, and the owner will want to know how much
 * stock was lost. It leaves the screen on the next poll.
 */
final class CancelKitchenTicket
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        if (! $this->capabilities->get()->hasModule('kitchen')) {
            return;
        }

        $ticket = KitchenTicketModel::where('order_id', $event->orderId)->first();

        // There may be no ticket: an order cancelled before confirmation never
        // reached the kitchen.
        if ($ticket === null) {
            return;
        }

        // If it has already left the kitchen the food is made and delivered:
        // cancelling the payment does not put it back on the griddle.
        if ($ticket->status === TicketStatus::Served || $ticket->status === TicketStatus::Cancelled) {
            return;
        }

        $ticket->update([
            'status' => TicketStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    }
}
