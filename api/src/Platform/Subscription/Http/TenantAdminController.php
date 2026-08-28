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
 * Los negocios, vistos desde la plataforma.
 *
 * Esta es la única parte del sistema que mira a **todos** los clientes a la
 * vez, y por eso está fuera del alcance de RLS: `tenants`, `subscriptions` y
 * `subscription_payments` son tablas globales. Lo que NO se hace desde aquí es
 * leer los datos de dentro de un negocio sin entrar en él como se entra
 * siempre — con su contexto puesto y quedando escrito.
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
            ->whereNull('tenants.deleted_at')
            ->when(
                $request->string('buscar')->isNotEmpty(),
                function ($q) use ($request) {
                    $termino = '%'.$request->string('buscar')->toString().'%';

                    return $q->where(fn ($w) => $w->where('tenants.name', 'ilike', $termino)
                        ->orWhere('tenants.slug', 'ilike', $termino));
                },
            )
            ->when(
                $request->string('estado')->isNotEmpty(),
                fn ($q) => $q->where('tenants.status', $request->string('estado')->toString()),
            )
            ->orderBy('tenants.name')
            ->get([
                'tenants.id', 'tenants.name', 'tenants.slug', 'tenants.status',
                'tenants.plan_code', 'tenants.created_at',
                'subscriptions.current_period_end', 'subscriptions.grace_days',
            ]);

        return response()->json([
            'data' => $rows->map(fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'status' => $row->status,
                'statusLabel' => TenantStatus::from($row->status)->label(),
                'planCode' => $row->plan_code,
                'currentPeriodEnd' => $row->current_period_end,
                // Cuántos días le quedan. En negativo, cuántos lleva vencido —
                // que es la cifra que uno quiere ver de un vistazo.
                'daysLeft' => $row->current_period_end === null
                    ? null
                    : (int) now()->startOfDay()->diffInDays($row->current_period_end, false),
                'createdAt' => $row->created_at,
            ])->all(),
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

        $resultado = $onboard->execute(
            name: $data['name'],
            slug: $data['slug'],
            planCode: $data['plan_code'],
            ownerName: $data['owner_name'],
            ownerEmail: $data['owner_email'],
            ownerPassword: $data['owner_password'],
        );

        return response()->json(['data' => $resultado], 201);
    }

    /**
     * La ficha de un negocio: su plan, su vencimiento, su uso y sus pagos.
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

                // El uso contra los techos del plan. `null` es ILIMITADO, nunca
                // cero: cero sería «ninguno», que es otra cosa y mucho peor de
                // depurar.
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

                // Lo que hicimos NOSOTROS en su casa. Se le puede enseñar.
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
     * Suspender, reactivar o cerrar a mano.
     *
     * Existe además del trabajo diario porque hay motivos que no son el pago:
     * un negocio que cierra, uno que pide pausa, uno que hay que parar por
     * abuso. Y queda escrito quién lo hizo.
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
     * Modo soporte: mirar un negocio **en sólo lectura**.
     *
     * Es lo que hace falta cuando un cliente llama diciendo «no me funciona»:
     * ver su carta, su equipo y sus últimos pedidos sin pedirle capturas de
     * pantalla.
     *
     * Tres cosas que lo mantienen honesto: **sólo lee** —no hay escritura por
     * esta puerta—, **queda escrito** en la bitácora de plataforma, y esa
     * bitácora **se le puede enseñar al dueño**. Entrar en casa de un cliente
     * sin que quede rastro es lo que no se hace.
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
        $contado = $this->session->within($tenantId, fn (): array => [
            'users' => DB::table('users')->where('is_active', true)->count(),
            'products' => DB::table('products')->count(),
            'ordersThisMonth' => DB::table('orders')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
        ]);

        return [
            'users' => ['used' => $contado['users'], 'max' => $plan?->max_users],
            'products' => ['used' => $contado['products'], 'max' => $plan?->max_products],
            'ordersThisMonth' => ['used' => $contado['ordersThisMonth'], 'max' => $plan?->max_orders_month],
        ];
    }
}
