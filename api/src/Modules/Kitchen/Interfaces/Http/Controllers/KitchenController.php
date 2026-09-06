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
 * The ticket board, over HTTP.
 */
final class KitchenController
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    /** How many tickets fit on the screen before it has to say something. */
    private const CAP = 120;

    /** @var list<string> */
    private const ON_SCREEN = ['pending', 'preparing', 'ready'];

    public function index(): JsonResponse
    {
        $tickets = KitchenTicketModel::query()
            ->with('items')
            // Served ones are not sent: history is reports' business, and a kitchen
            // screen showing yesterday is a screen nobody looks at.
            ->whereIn('status', self::ON_SCREEN)
            // Oldest first: the order they have to be made in.
            ->orderBy('placed_at')
            // A cap in case something gets out of hand.
            ->limit(self::CAP)
            ->get();

        /*
         * If there are more than fit, it SAYS SO.
         *
         * Ordered oldest to newest, what falls off the end are the just-arrived
         * tickets. Truncating silently means a busy kitchen stops seeing new
         * orders, with no warning and a customer waiting.
         */
        $live = $tickets->count() < self::CAP
            ? $tickets->count()
            : KitchenTicketModel::query()->whereIn('status', self::ON_SCREEN)->count();

        return response()->json([
            'data' => $tickets->map($this->present(...))->all(),
            'meta' => [
                'total' => $live,
                'hidden' => max(0, $live - $tickets->count()),
                // Travels in the response rather than fixed in the screen: every tenant
                // has its own idea of "running late".
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
            // The button says what will happen, and the server supplies the text so
            // the screen keeps no state table of its own.
            'nextLabel' => $ticket->status->nextLabel(),

            'serviceType' => $ticket->service_type,
            'takenByName' => $ticket->taken_by_name,
            'notes' => $ticket->notes,
            'prepMinutes' => $ticket->prep_minutes,
            'placedAt' => $ticket->placed_at?->toAtomString(),

            /*
             * The stopwatch is computed by the SERVER: a kitchen tablet's clock
             * is almost never set right, and the traffic light would lie all day
             * with nobody knowing why.
             */
            'waitingSeconds' => $ticket->placed_at === null
                ? 0
                : max(0, (int) round(now()->diffInSeconds($ticket->placed_at, absolute: true))),

            'items' => $ticket->items->map(fn ($item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                // Already text, ready to read while cooking.
                'modifiers' => $item->modifiers ?? [],
                'notes' => $item->notes,
            ])->all(),
        ];
    }
}
