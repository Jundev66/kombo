<?php

declare(strict_types=1);

namespace Modules\Kitchen\Application\UseCases;

use App\Models\Kitchen\KitchenTicketModel;
use Modules\Kitchen\Domain\Exceptions\InvalidKitchenTransition;
use Modules\Kitchen\Domain\ValueObjects\TicketStatus;
use Platform\Audit\Actor;
use Platform\Audit\AuditLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Moving a ticket to the next step. Three rules, all from watching a real
 * kitchen:
 *
 * 1. Repeating the same step is not an error — two cooks tapping "Ready" at
 *    once cannot raise a red message mid-service.
 * 2. Forwards only, one step at a time; a stray tap must not send a delivered
 *    ticket back to "to do" and get the food made twice.
 * 3. Every step stamps its time, which is where "how long did we take" and the
 *    traffic light's calibration come from.
 */
final class AdvanceTicket
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(string $ticketId, TicketStatus $to, ?string $byName = null): KitchenTicketModel
    {
        $ticket = KitchenTicketModel::with('items')->find($ticketId)
            ?? throw new NotFoundHttpException('Esa comanda no existe en este negocio.');

        $from = $ticket->status;

        // Idempotent: the same step again returns the ticket unchanged.
        if ($from === $to) {
            return $ticket;
        }

        if ($from->next() !== $to) {
            throw new InvalidKitchenTransition($from, $to);
        }

        $ticket->status = $to;
        $ticket->{self::stampColumn($to)} = now();

        if ($byName !== null) {
            $ticket->taken_by_name = $byName;
        }

        $ticket->save();

        $this->audit->record(
            action: 'kitchen.advanced',
            entityType: 'kitchen_ticket',
            entityId: $ticketId,
            before: ['status' => $from->value],
            after: ['status' => $to->value],
            // In the name of whoever entered their PIN, not the tablet's token.
            actor: $byName === null ? null : new Actor((string) auth()->id(), $byName),
        );

        return $ticket;
    }

    private static function stampColumn(TicketStatus $status): string
    {
        return match ($status) {
            TicketStatus::Preparing => 'started_at',
            TicketStatus::Ready => 'ready_at',
            TicketStatus::Served => 'served_at',
            TicketStatus::Pending => 'placed_at',
        };
    }
}
