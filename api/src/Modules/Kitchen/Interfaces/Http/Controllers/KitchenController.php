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

    /** Cuántas comandas caben en la pantalla antes de tener que avisar. */
    private const TOPE = 120;

    /** @var list<string> */
    private const EN_PANTALLA = ['pending', 'preparing', 'ready'];

    public function index(): JsonResponse
    {
        $tickets = KitchenTicketModel::query()
            ->with('items')
            // Las servidas NO se mandan: el histórico es cosa de reportes, y
            // una pantalla de cocina con lo de ayer es una pantalla que nadie
            // mira.
            ->whereIn('status', self::EN_PANTALLA)
            // La más vieja primero: es el orden en el que hay que hacerlas.
            ->orderBy('placed_at')
            // Un tope por si algo se descontrola.
            ->limit(self::TOPE)
            ->get();

        /*
         * Si hay más de las que caben, **se dice**.
         *
         * Antes se cortaba en silencio, y ése es el peor fallo posible en esta
         * pantalla: como el orden es de la más vieja a la más nueva, lo que se
         * queda fuera son las comandas RECIÉN entradas. Una cocina que nunca
         * marca nada como servido pasa el tope, y a partir de ahí los pedidos
         * nuevos sencillamente no aparecen — sin ningún aviso, y con el cliente
         * esperando comida que nadie está haciendo.
         */
        $vivas = $tickets->count() < self::TOPE
            ? $tickets->count()
            : KitchenTicketModel::query()->whereIn('status', self::EN_PANTALLA)->count();

        return response()->json([
            'data' => $tickets->map($this->present(...))->all(),
            'meta' => [
                'total' => $vivas,
                'hidden' => max(0, $vivas - $tickets->count()),
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
