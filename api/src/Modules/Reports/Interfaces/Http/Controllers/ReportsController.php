<?php

declare(strict_types=1);

namespace Modules\Reports\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reports\Application\UseCases\SalesReport;

/**
 * The sales, in a single call: the owner opens this on their phone, often with
 * poor signal, and five requests are five chances to see half a screen.
 */
final class ReportsController
{
    public function __invoke(Request $request, SalesReport $report): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:today,yesterday,week,month'],
        ]);

        // Resolved on the SERVER in the tenant's local time: computing "today" on
        // the phone would use the phone's timezone.
        [$from, $until] = $report->range($data['period'] ?? 'today');

        return response()->json(['data' => $report->forRange($from, $until)]);
    }
}
