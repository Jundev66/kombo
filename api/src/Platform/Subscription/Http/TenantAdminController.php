<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use App\Models\Platform\SubscriptionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Platform\Subscription\OnboardTenant;
use Platform\Subscription\PlatformAudit;
use Platform\Subscription\Subscriptions;
use Platform\Tenancy\TenantSession;
use Platform\Tenancy\TenantStatus;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tenants, seen from the platform.
 *
 * The only part of the system that looks at every customer at once, which is
 * why it works on the global tables. What is NOT done from here is reading data
 * from inside a tenant without entering it the usual way — with its context set
 * and a record left behind.
 */
final class TenantAdminController
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly PlatformAudit $audit,
        private readonly TenantSession $session,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            // To show the plan's NAME rather than its code: "Negocio", not
            // `business`. A lowercase identifier on a screen a person reads is the
            // same as not translating it.
            ->leftJoin('plans', 'plans.code', '=', 'tenants.plan_code')
            ->whereNull('tenants.deleted_at')
            ->when(
                $request->string('search')->isNotEmpty(),
                function ($q) use ($request) {
                    $term = '%'.$request->string('search')->toString().'%';

                    return $q->where(fn ($w) => $w->where('tenants.name', 'ilike', $term)
                        ->orWhere('tenants.slug', 'ilike', $term));
                },
            )
            ->when(
                $request->string('estado')->isNotEmpty(),
                fn ($q) => $q->where('tenants.status', $request->string('estado')->toString()),
            )
            ->orderBy('tenants.name')
            /*
             * Paginated. It was not, and that is the opposite problem to the
             * dashboard's: there was no cap at all, so the screen downloaded
             * EVERY tenant on the platform.
             */
            ->paginate(50, [
                'tenants.id', 'tenants.name', 'tenants.slug', 'tenants.status',
                'tenants.plan_code', 'tenants.created_at',
                'plans.name as plan_name',
                'subscriptions.current_period_end', 'subscriptions.grace_days',
            ]);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'status' => $row->status,
                'statusLabel' => TenantStatus::from($row->status)->label(),
                'planCode' => $row->plan_code,
                // With a fallback: a tenant whose plan was withdrawn from the catalog
                // would still carry its code, and a blank would look like no plan.
                'planName' => $row->plan_name ?? $row->plan_code,
                'currentPeriodEnd' => $row->current_period_end,
                // Days left. Negative means days overdue — the figure you want at a
                // glance.
                'daysLeft' => $row->current_period_end === null
                    ? null
                    : (int) now()->startOfDay()->diffInDays($row->current_period_end, false),
                'createdAt' => $row->created_at,
            ])->all(),
            'meta' => [
                'page' => $rows->currentPage(),
                'lastPage' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(Request $request, OnboardTenant $onboard): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:40'],
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:160'],
            'owner_password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $result = $onboard->execute(
            name: $data['name'],
            slug: $data['slug'],
            planCode: $data['plan_code'],
            ownerName: $data['owner_name'],
            ownerEmail: $data['owner_email'],
            ownerPassword: $data['owner_password'],
        );

        return response()->json(['data' => $result], 201);
    }

    /**
     * A tenant's record: plan, expiry, usage and payments.
     */
    public function show(string $id): JsonResponse
    {
        $tenant = DB::table('tenants')->where('id', $id)->first()
            ?? throw new NotFoundHttpException('Ese negocio no existe.');

        $subscription = SubscriptionModel::where('tenant_id', $id)->first();
        $plan = DB::table('plans')->where('code', $tenant->plan_code)->first();

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'statusLabel' => TenantStatus::from($tenant->status)->label(),
                'createdAt' => $tenant->created_at,

                'subscription' => $subscription === null ? null : [
                    'planCode' => $subscription->plan_code,
                    'status' => $subscription->status,
                    'currentPeriodEnd' => $subscription->current_period_end->toAtomString(),
                    'graceDays' => $subscription->grace_days,
                    'suspendsAt' => $subscription->suspendsAt()->format(DATE_ATOM),
                    'daysLeft' => $subscription->daysLeft(),
                ],

                // Usage against the plan ceilings. `null` is UNLIMITED, never zero.
                'usage' => $this->usage($id, $plan),

                'payments' => $subscription === null ? [] : $subscription->payments()
                    ->limit(24)
                    ->get()
                    ->map(fn ($p): array => [
                        'amountCents' => $p->amount_cents,
                        'method' => $p->method,
                        'reference' => $p->reference,
                        'paidAt' => $p->paid_at->toDateString(),
                        'periodTo' => $p->period_to->toDateString(),
                    ])->all(),

                // What WE did in their house. It can be shown to them.
                'platformLog' => $this->audit->forTenant($id, 20),
            ],
        ]);
    }

    public function registerPayment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:pago_movil,transfer,zelle,cash,binance'],
            'months' => ['integer', 'min:1', 'max:24'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $subscription = SubscriptionModel::where('tenant_id', $id)->first()
            ?? throw new NotFoundHttpException('Ese negocio no tiene suscripción.');

        $this->subscriptions->registerPayment(
            subscription: $subscription,
            amountCents: $data['amount_cents'],
            method: $data['method'],
            months: $data['months'] ?? 1,
            reference: $data['reference'] ?? null,
        );

        return response()->json(['data' => ['currentPeriodEnd' => $subscription->refresh()->current_period_end->toAtomString()]]);
    }

    /**
     * Suspend, reactivate or close by hand.
     *
     * Alongside the daily job, because some reasons are not payment: a tenant
     * closing down, one asking for a pause, one to stop for abuse. Who did it
     * is recorded.
     */
    public function changeStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended,closed'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $status = TenantStatus::from($data['status']);

        $this->subscriptions->setTenantStatus($id, $status);

        $this->audit->record('tenant.status_changed', $id, [
            'a' => $status->value,
            'motivo' => $data['reason'] ?? null,
        ]);

        return response()->json(['data' => ['status' => $status->value]]);
    }

    public function changePlan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
        ]);

        $subscription = SubscriptionModel::where('tenant_id', $id)->first()
            ?? throw new NotFoundHttpException('Ese negocio no tiene suscripción.');

        $this->subscriptions->changePlan($subscription, $data['plan_code']);

        return response()->json(['data' => ['planCode' => $data['plan_code']]]);
    }

    /**
     * Support mode: viewing a tenant READ-ONLY.
     *
     * What is needed when a customer calls saying "it doesn't work": see their
     * menu, team and recent orders without asking for screenshots.
     *
     * Three things keep it honest — it only reads, it is written to the
     * platform audit log, and that log can be shown to the owner.
     */
    public function support(string $id): JsonResponse
    {
        $tenant = DB::table('tenants')->where('id', $id)->first()
            ?? throw new NotFoundHttpException('Ese negocio no existe.');

        $this->audit->record('support_access', $id, ['slug' => $tenant->slug]);

        $snapshot = $this->session->within($id, fn (): array => [
            'products' => DB::table('products')->where('is_active', true)->count(),
            'team' => DB::table('users')->where('is_active', true)
                ->get(['name', 'email', 'last_login_at'])
                ->map(fn (object $u): array => [
                    'name' => $u->name,
                    'email' => $u->email,
                    'lastLoginAt' => $u->last_login_at,
                ])->all(),
            'modules' => DB::table('tenant_modules')->where('enabled', true)->pluck('module_code')->all(),
            'lastOrders' => DB::table('orders')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['number', 'status', 'channel', 'total_cents', 'created_at'])
                ->map(fn (object $o): array => [
                    'number' => $o->number,
                    'status' => $o->status,
                    'channel' => $o->channel,
                    'totalCents' => $o->total_cents,
                    'at' => $o->created_at,
                ])->all(),
        ]);

        return response()->json(['data' => $snapshot]);
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(string $tenantId, ?object $plan): array
    {
        $counted = $this->session->within($tenantId, fn (): array => [
            'users' => DB::table('users')->where('is_active', true)->count(),
            'products' => DB::table('products')->count(),
            'ordersThisMonth' => DB::table('orders')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
        ]);

        return [
            'users' => ['used' => $counted['users'], 'max' => $plan?->max_users],
            'products' => ['used' => $counted['products'], 'max' => $plan?->max_products],
            'ordersThisMonth' => ['used' => $counted['ordersThisMonth'], 'max' => $plan?->max_orders_month],
        ];
    }
}
