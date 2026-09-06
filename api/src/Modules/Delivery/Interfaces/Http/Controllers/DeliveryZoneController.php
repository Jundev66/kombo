<?php

declare(strict_types=1);

namespace Modules\Delivery\Interfaces\Http\Controllers;

use App\Models\Delivery\DeliveryZoneModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The delivery zones: a neighbourhood, its fee, and how long it takes.
 */
final class DeliveryZoneController
{
    public function index(Request $request): JsonResponse
    {
        $zones = DeliveryZoneModel::query()
            // The dashboard needs the switched-off ones to switch them back on; the
            // portal does not.
            ->when(! $request->boolean('incluir_inactivas'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $zones->map(self::asArray(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $zone = DeliveryZoneModel::create($request->validate(self::rules()));

        return response()->json(['data' => self::asArray($zone)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $zone = DeliveryZoneModel::find($id) ?? throw new NotFoundHttpException('Esa zona no existe.');

        $zone->update($request->validate(self::rules(partial: true)));

        return response()->json(['data' => self::asArray($zone->refresh())]);
    }

    /**
     * Switching a zone off, not deleting it: an order from two months ago went
     * somewhere, and deleting the zone would leave it unexplained.
     */
    public function destroy(string $id): JsonResponse
    {
        $zone = DeliveryZoneModel::find($id) ?? throw new NotFoundHttpException('Esa zona no existe.');

        $zone->update(['is_active' => false]);

        return response()->json(status: 204);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:80'],
            'fee_cents' => [$required, 'integer', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(DeliveryZoneModel $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'feeCents' => $zone->fee_cents,
            'estimatedMinutes' => $zone->estimated_minutes,
            'isActive' => $zone->is_active,
            'sortOrder' => $zone->sort_order,
        ];
    }
}
