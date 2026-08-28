<?php

declare(strict_types=1);

namespace Modules\Delivery\Interfaces\Http\Controllers;

use App\Models\Delivery\DeliveryZoneModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las zonas de reparto: un barrio, su tarifa y cuánto se tarda en llegar.
 */
final class DeliveryZoneController
{
    public function index(Request $request): JsonResponse
    {
        $zones = DeliveryZoneModel::query()
            // El panel necesita ver las apagadas para volver a encenderlas; el
            // portal, no.
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
     * Apagar una zona, no borrarla.
     *
     * Un pedido de hace dos meses fue a algún sitio, y borrar la zona dejaría
     * ese pedido sin explicación. Se apaga: deja de ofrecerse y lo viejo sigue
     * legible.
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
