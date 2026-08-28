<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Platform\Tenancy\TenantSession;

/**
 * Cómo va el negocio del negocio.
 *
 * Cuatro cifras y ninguna más. Un tablero con veinte gráficos es un tablero que
 * nadie mira: éstas son las que contestan «¿esto va bien?» en cinco segundos.
 *
 * **Los pedidos se cuentan entrando en cada negocio, uno por uno.** No es
 * elegante y es a propósito: `orders` lleva RLS, así que no hay una consulta
 * que los sume todos, y la alternativa —darle a la plataforma un usuario que se
 * salte RLS— sería abrir justo el agujero que RLS existe para tapar. Se cachea
 * cinco minutos, que para un tablero es tiempo real de sobra.
 */
final class MetricsController
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly TenantSession $session) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => Cache::remember('platform:metrics', self::CACHE_SECONDS, fn (): array => [
                'tenants' => [
                    'active' => DB::table('tenants')->whereIn('status', ['trial', 'active'])->whereNull('deleted_at')->count(),
                    'pastDue' => DB::table('tenants')->where('status', 'past_due')->count(),
                    'suspended' => DB::table('tenants')->where('status', 'suspended')->count(),
                    'newThisMonth' => DB::table('tenants')
                        ->where('created_at', '>=', now()->startOfMonth())
                        ->whereNull('deleted_at')
                        ->count(),
                ],

                /*
                 * El ingreso recurrente: lo que valen los planes de los
                 * negocios que están al día.
                 *
                 * Se calcula sobre el precio del PLAN y no sobre lo cobrado el
                 * mes pasado: lo primero dice cuánto entra si nadie se va, que
                 * es la pregunta; lo segundo dice cuánto entró, que ya lo sabe
                 * el historial de pagos.
                 */
                'mrrCents' => (int) DB::table('tenants')
                    ->join('plans', 'plans.code', '=', 'tenants.plan_code')
                    ->whereIn('tenants.status', ['active', 'past_due'])
                    ->whereNull('tenants.deleted_at')
                    ->sum('plans.price_cents'),

                'collectedThisMonthCents' => (int) DB::table('subscription_payments')
                    ->where('paid_at', '>=', now()->startOfMonth())
                    ->sum('amount_cents'),

                'ordersThisMonth' => $this->ordersThisMonth(),

                'expiringSoon' => DB::table('subscriptions')
                    ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
                    ->whereNull('subscriptions.cancelled_at')
                    ->whereBetween('subscriptions.current_period_end', [now(), now()->addDays(7)])
                    ->orderBy('subscriptions.current_period_end')
                    ->limit(20)
                    ->get(['tenants.name', 'tenants.slug', 'subscriptions.current_period_end'])
                    ->map(fn (object $row): array => [
                        'name' => $row->name,
                        'slug' => $row->slug,
                        'endsAt' => $row->current_period_end,
                        'daysLeft' => (int) now()->startOfDay()->diffInDays($row->current_period_end, false),
                    ])->all(),
            ]),
        ]);
    }

    private function ordersThisMonth(): int
    {
        $total = 0;

        $tenants = DB::table('tenants')
            ->whereNull('deleted_at')
            ->whereIn('status', ['trial', 'active', 'past_due'])
            ->pluck('id');

        foreach ($tenants as $tenantId) {
            $total += $this->session->within((string) $tenantId, fn (): int => DB::table('orders')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count());
        }

        return $total;
    }
}
