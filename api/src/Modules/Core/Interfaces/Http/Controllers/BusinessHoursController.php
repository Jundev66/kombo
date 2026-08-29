<?php

declare(strict_types=1);

namespace Modules\Core\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Tenancy\TenantContext;

/**
 * A qué hora abre el negocio.
 *
 * Lo usa el portal para no aceptar pedidos a las tres de la mañana, y el bot
 * para responder «hoy cerramos a las 8» en vez de dejar a alguien esperando.
 */
final class BusinessHoursController
{
    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('business_hours')->orderBy('weekday')->get()->keyBy('weekday');

        // Se devuelven los siete días SIEMPRE, aunque no haya filas. Una
        // pantalla que tenga que inventar los días que faltan acaba
        // inventándolos distinto que el portal.
        $data = [];
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $row = $rows->get($weekday);

            $data[] = [
                'weekday' => $weekday,
                'label' => self::DIAS[$weekday],
                // Recortado a `H:i`, que es lo que acepta el PUT de abajo:
                // PostgreSQL devuelve «08:00:00» y el formulario no podría
                // guardar lo que acaba de leer.
                'opensAt' => $row?->opens_at === null ? null : substr((string) $row->opens_at, 0, 5),
                'closesAt' => $row?->closes_at === null ? null : substr((string) $row->closes_at, 0, 5),
                // Sin fila configurada, se asume CERRADO. Es el fallo seguro:
                // aceptar pedidos de un día que nadie configuró es peor que no
                // aceptarlos.
                'isClosed' => $row === null ? true : (bool) $row->is_closed,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'days.*.is_closed' => ['required', 'boolean'],
            'days.*.opens_at' => ['nullable', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'date_format:H:i'],
        ]);

        $tenantId = $this->context->id();

        DB::transaction(function () use ($data, $tenantId): void {
            foreach ($data['days'] as $day) {
                DB::table('business_hours')->upsert(
                    [[
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'weekday' => $day['weekday'],
                        'opens_at' => $day['is_closed'] ? null : ($day['opens_at'] ?? null),
                        'closes_at' => $day['is_closed'] ? null : ($day['closes_at'] ?? null),
                        'is_closed' => $day['is_closed'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['tenant_id', 'weekday'],
                    ['opens_at', 'closes_at', 'is_closed', 'updated_at'],
                );
            }
        });

        return $this->index();
    }
}
