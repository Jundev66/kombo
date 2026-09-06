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
 * The rate of the day.
 *
 * The owner enters it from their phone before opening. A ten-second gesture
 * that governs everything charged that day, so this screen has to be the
 * simplest in the system.
 */
final class ExchangeRateController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** Today's, or the most recent one there is. */
    public function current(): JsonResponse
    {
        $rate = DB::table('exchange_rates')
            ->orderByDesc('effective_date')
            ->first();

        if ($rate === null) {
            // With no rate there is no charging in bolívares, and that is said plainly
            // rather than returning a zero that looks valid.
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'rate' => (float) $rate->rate,
                'source' => $rate->source,
                'effectiveDate' => $rate->effective_date,
                // So the screen can warn "this rate is from yesterday" without doing date
                // arithmetic on the client.
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

        // The value object rejects zero: it would turn every price into zero, and
        // the first to find out would be the customer.
        $rate = ExchangeRate::of($data['rate']);
        $source = $data['source'] ?? 'custom';
        $today = now()->toDateString();

        $previous = DB::table('exchange_rates')
            ->where('effective_date', $today)
            ->where('source', $source)
            ->value('rate');

        // Correcting today's REPLACES it rather than stacking versions with no
        // idea which was used to charge.
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
            before: $previous === null ? null : ['rate' => (float) $previous],
            after: ['rate' => $rate->asFloat(), 'source' => $source],
        );

        return response()->json(['data' => ['rate' => $rate->asFloat(), 'effectiveDate' => $today]], 201);
    }
}
