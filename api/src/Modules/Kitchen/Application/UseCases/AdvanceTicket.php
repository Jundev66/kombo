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
 * Mover una comanda al siguiente paso.
 *
 * Tres reglas, y las tres salen de mirar cómo funciona una cocina de verdad:
 *
 * 1. **Repetir el mismo paso NO es error.** Dos cocineros tocando «Listo» a la
 *    vez no pueden hacer saltar un mensaje rojo en mitad del servicio.
 * 2. **Sólo hacia adelante y sólo un paso.** Un toque accidental que devuelva
 *    a «por hacer» una comanda ya entregada hace que se prepare dos veces.
 *    Corregir de verdad es cosa del encargado, desde el panel.
 * 3. **Cada paso sella su hora.** De ahí sale «cuánto tardamos», que es la
 *    única forma de saber si el semáforo está bien calibrado.
 */
final class AdvanceTicket
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(string $ticketId, TicketStatus $to, ?string $byName = null): KitchenTicketModel
    {
        $ticket = KitchenTicketModel::with('items')->find($ticketId)
            ?? throw new NotFoundHttpException('Esa comanda no existe en este negocio.');

        $from = $ticket->status;

        // Idempotente: el mismo paso otra vez devuelve la comanda tal cual.
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
            // A nombre de quien puso su PIN, no del token de la tablet.
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
