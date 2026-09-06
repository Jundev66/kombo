<?php

declare(strict_types=1);

namespace Modules\Core\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Tenancy\TenantContext;

/**
 * What time the tenant opens. The portal uses it to refuse orders at three in
 * the morning, and the bot to answer "we close at 8 today".
 */
final class BusinessHoursController
{
    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('business_hours')->orderBy('weekday')->get()->keyBy('weekday');

        // All seven days ALWAYS, even with no rows: a screen that invents the
        // missing days ends up inventing them differently from the portal.
        $data = [];
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $row = $rows->get($weekday);

            $data[] = [
                'weekday' => $weekday,
                'label' => self::DIAS[$weekday],
                // Trimmed to `H:i`, which is what the PUT below accepts: PostgreSQL
                // returns "08:00:00" and the form could not save what it just read.
                'opensAt' => $row?->opens_at === null ? null : substr((string) $row->opens_at, 0, 5),
                'closesAt' => $row?->closes_at === null ? null : substr((string) $row->closes_at, 0, 5),
                // With no row configured, CLOSED. The safe failure: taking orders on an
                // unconfigured day is worse than not taking them.
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
