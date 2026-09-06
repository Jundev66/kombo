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
     * Reprinting. How many times is counted: a note reprinted five times is a
     * question somebody will want to ask.
     */
    public function reprint(string $id): JsonResponse
    {
        $note = DeliveryNoteModel::find($id) ?? throw new NotFoundHttpException('Esa nota no existe en este negocio.');

        $note->increment('printed_count');

        return response()->json(['data' => DeliveryNoteResource::make($note->refresh())]);
    }

    /*
     * No `void()` here. Voiding a note is voiding the whole sale, so that
     * operation lives at the till, which knows how to cancel the order too.
     */
}
