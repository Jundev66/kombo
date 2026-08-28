<?php

declare(strict_types=1);

namespace Modules\Kitchen\Interfaces\Http\Controllers;

use App\Models\Kitchen\KitchenTicketModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Kitchen\Application\UseCases\AdvanceTicket;
use Modules\Kitchen\Domain\ValueObjects\TicketStatus;
use Platform\Capabilities\CurrentCapabilities;

/**
 * La pantalla de comandas, por HTTP.
 */
final class KitchenController
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function index(): JsonResponse
    {
        $tickets = KitchenTicketModel::query()
            ->with('items')
            // Las servidas NO se mandan: el histórico es cosa de reportes, y
            // una pantalla de cocina con lo de ayer es una pantalla que nadie
            // mira.
            ->whereIn('status', [
                TicketStatus::Pending->value,
                TicketStatus::Preparing->value,
                TicketStatus::Ready->value,
            ])
            // La más vieja primero: es el orden en el que hay que hacerlas.
            ->orderBy('placed_at')
            // Un tope por si algo se descontrola. Ciento veinte comandas vivas
            // ya es un problema de otra clase, y no lo arregla la pantalla.
            ->limit(120)
            ->get();

        return response()->json([
            'data' => $tickets->map($this->present(...))->all(),
            'meta' => [
                // Viaja en la respuesta y no fijo en la pantalla: cada negocio
                // tiene su idea de «va tarde».
                'staleMinutes' => (int) $this->capabilities->get()->setting('kitchen.stale_minutes', 15),
            ],
        ]);
    }

    public function advance(Request $request, string $id, AdvanceTicket $advance): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:preparing,ready,served'],
        ]);

        $ticket = $advance->execute(
            ticketId: $id,
            to: TicketStatus::from($data['status']),
            byName: $request->user()?->name,
        );

        return response()->json(['data' => $this->present($ticket)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(KitchenTicketModel $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'status' => $ticket->status->value,
            'nextStatus' => $ticket->status->next()?->value,
            // El botón dice lo que va a pasar, y el texto lo pone el servidor
            // para que la pantalla no tenga su propia tabla de estados.
            'nextLabel' => $ticket->status->nextLabel(),

            'serviceType' => $ticket->service_type,
            'takenByName' => $ticket->taken_by_name,
            'notes' => $ticket->notes,
            'prepMinutes' => $ticket->prep_minutes,
            'placedAt' => $ticket->placed_at?->toAtomString(),

            /*
             * **El cronómetro lo calcula el SERVIDOR.**
             *
             * El reloj de una tablet de cocina casi nunca está bien puesto:
             * nadie la configura, se queda sin batería, cambia el horario. Si
             * el tiempo se calculara ahí, el semáforo mentiría todo el día y
             * nadie sabría por qué.
             */
            'waitingSeconds' => $ticket->placed_at === null
                ? 0
                : max(0, (int) round(now()->diffInSeconds($ticket->placed_at, absolute: true))),

            'items' => $ticket->items->map(fn ($item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                // Ya en texto, listos para leer mientras se cocina.
                'modifiers' => $item->modifiers ?? [],
                'notes' => $item->notes,
            ])->all(),
        ];
    }
}
