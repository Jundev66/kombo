<?php

declare(strict_types=1);

namespace Modules\Core\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;
use Shared\Domain\ValueObjects\ExchangeRate;

/**
 * La tasa del día.
 *
 * El dueño la carga desde el teléfono, normalmente antes de abrir. Es un gesto
 * de diez segundos que condiciona todo lo que se cobra ese día, así que la
 * pantalla que lo hace tiene que ser la más simple del sistema.
 */
final class ExchangeRateController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** La vigente hoy, o la más reciente que haya. */
    public function current(): JsonResponse
    {
        $rate = DB::table('exchange_rates')
            ->orderByDesc('effective_date')
            ->first();

        if ($rate === null) {
            // Sin tasa no se puede cobrar en bolívares, y hay que decirlo
            // claro en vez de devolver un cero que parezca válido.
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'rate' => (float) $rate->rate,
                'source' => $rate->source,
                'effectiveDate' => $rate->effective_date,
                // Para que la pantalla pueda avisar «esta tasa es de ayer»
                // sin tener que calcular fechas en el cliente.
                'isToday' => $rate->effective_date === now()->toDateString(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rate' => ['required', 'numeric'],
            'source' => ['nullable', 'string', 'in:bcv,custom'],
        ]);

        // El value object valida que sea mayor que cero: una tasa de cero
        // convertiría todos los precios en cero, y el primero en enterarse
        // sería el cliente.
        $rate = ExchangeRate::of($data['rate']);
        $source = $data['source'] ?? 'custom';
        $today = now()->toDateString();

        $anterior = DB::table('exchange_rates')
            ->where('effective_date', $today)
            ->where('source', $source)
            ->value('rate');

        // Corregir la del día es REEMPLAZARLA, no acumular tres versiones y
        // no saber cuál se usó al cobrar.
        DB::table('exchange_rates')->upsert(
            [[
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->context->id(),
                'rate' => $rate->value,
                'source' => $source,
                'effective_date' => $today,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['tenant_id', 'effective_date', 'source'],
            ['rate', 'updated_at'],
        );

        $this->audit->record(
            action: 'core.exchange_rate_set',
            entityType: 'exchange_rate',
            before: $anterior === null ? null : ['rate' => (float) $anterior],
            after: ['rate' => $rate->asFloat(), 'source' => $source],
        );

        return response()->json(['data' => ['rate' => $rate->asFloat(), 'effectiveDate' => $today]], 201);
    }
}
