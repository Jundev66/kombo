<?php

declare(strict_types=1);

namespace Modules\Documents\Interfaces\Http\Controllers;

use App\Models\Documents\DeliveryNoteModel;
use Illuminate\Http\JsonResponse;
use Modules\Documents\Interfaces\Http\Resources\DeliveryNoteResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeliveryNoteController
{
    public function index(): JsonResponse
    {
        $notes = DeliveryNoteModel::query()
            ->orderByDesc('issued_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $notes->map(DeliveryNoteResource::make(...))->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $note = DeliveryNoteModel::find($id) ?? throw new NotFoundHttpException('Esa nota no existe en este negocio.');

        return response()->json(['data' => DeliveryNoteResource::make($note)]);
    }

    /**
     * Reimprimir.
     *
     * Se cuenta cuántas veces: una nota reimpresa cinco veces es una pregunta
     * que alguien va a querer hacerse.
     */
    public function reprint(string $id): JsonResponse
    {
        $note = DeliveryNoteModel::find($id) ?? throw new NotFoundHttpException('Esa nota no existe en este negocio.');

        $note->increment('printed_count');

        return response()->json(['data' => DeliveryNoteResource::make($note->refresh())]);
    }

    /*
     * No hay `void()` aquí. Anular una nota es anular la venta entera —una
     * nota por pedido, y no se reemite—, así que esa operación vive en la caja,
     * que es la que sabe cancelar el pedido además del papel.
     */
}
