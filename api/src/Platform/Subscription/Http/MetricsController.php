<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Platform\Tenancy\TenantSession;

/**
 * How the business of the business is doing. Four figures and no more.
 *
 * Orders are counted by entering each tenant one at a time. It is not elegant
 * and it is deliberate: `orders` is under RLS, and the alternative — giving the
 * platform a user that bypasses RLS — would open the very hole RLS exists to
 * close. Cached for five minutes.
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
                 * Recurring revenue: what the plans of the up-to-date tenants
                 * are worth.
                 *
                 * Computed on the PLAN price rather than on last month's
                 * takings: the first answers "what comes in if nobody leaves",
                 * which is the question; the second is already in the payment
                 * history.
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
