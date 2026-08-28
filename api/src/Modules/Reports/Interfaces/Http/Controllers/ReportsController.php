<?php

declare(strict_types=1);

namespace Modules\Reports\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reports\Application\UseCases\SalesReport;

/**
 * Las ventas, en una sola llamada.
 *
 * Una y no cinco: el dueño abre esto desde el teléfono, muchas veces con mala
 * señal, y cinco peticiones son cinco oportunidades de ver la pantalla a
 * medias.
 */
final class ReportsController
{
    public function __invoke(Request $request, SalesReport $report): JsonResponse
    {
        $data = $request->validate([
            'periodo' => ['nullable', 'string', 'in:hoy,ayer,semana,mes'],
        ]);

        // Los atajos se resuelven en el SERVIDOR y en la hora del negocio:
        // calcular «hoy» en el teléfono usaría el huso del teléfono.
        [$desde, $hasta] = $report->range($data['periodo'] ?? 'hoy');

        return response()->json(['data' => $report->forRange($desde, $hasta)]);
    }
}
