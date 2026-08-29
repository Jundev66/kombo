<?php

declare(strict_types=1);

namespace Platform\Subscription;

use App\Models\Platform\SubscriptionModel;
use App\Models\Platform\SubscriptionPaymentModel;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Tenancy\TenantResolver;
use Platform\Tenancy\TenantStatus;

/**
 * El ciclo de vida del cobro: empezar, cobrar, vencer, suspender.
 *
 * Toda la aritmética de fechas vive aquí y en ningún otro sitio. Es poca y es
 * aburrida, y por eso mismo estaría repetida en tres pantallas si no tuviera
 * casa: el día que alguien la cambie en una y no en las otras, el negocio
 * cobrado se suspende igual y nadie sabe por qué.
 */
final class Subscriptions
{
    /**
     * A cuántos días de vencer se avisa.
     *
     * Dos veces y no cinco: un aviso que llega todos los días deja de leerse, y
     * el que importa —el de mañana— se pierde entre los otros.
     *
     * @var list<int>
     */
    private const AVISOS = [7, 3];

    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly TenantResolver $tenants,
    ) {}

    /**
     * Arranca la suscripción de un negocio nuevo.
     *
     * Con período de prueba si el plan lo trae. Y **empieza pagado hasta el
     * final de la prueba**, no «sin fecha»: un negocio sin `current_period_end`
     * es un negocio que el trabajo diario no sabe si suspender.
     */
    public function start(string $tenantId, string $planCode): SubscriptionModel
    {
        $trialDays = (int) (DB::table('plans')->where('code', $planCode)->value('trial_days') ?? 0);

        return SubscriptionModel::create([
            'tenant_id' => $tenantId,
            'plan_code' => $planCode,
            'status' => $trialDays > 0 ? 'trial' : 'active',
            'started_at' => now(),
            'current_period_end' => now()->addDays(max($trialDays, 1)),
        ]);
    }

    /**
     * Anota un pago y **extiende el período**.
     *
     * Desde el vencimiento actual si todavía no llegó, y desde hoy si ya pasó.
     * La diferencia importa y es la que la gente espera: quien paga con tres
     * días de adelanto no pierde esos tres días, y quien paga con diez de
     * retraso no compra diez días que ya vivió.
     */
    public function registerPayment(
        SubscriptionModel $subscription,
        int $amountCents,
        string $method,
        int $months = 1,
        ?string $reference = null,
        ?DateTimeImmutable $paidAt = null,
    ): SubscriptionPaymentModel {
        return DB::transaction(function () use ($subscription, $amountCents, $method, $months, $reference, $paidAt): SubscriptionPaymentModel {
            $desde = Carbon::instance($subscription->current_period_end)->isFuture()
                ? Carbon::instance($subscription->current_period_end)
                : now();

            $hasta = $desde->copy()->addMonths(max($months, 1));

            $payment = SubscriptionPaymentModel::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'amount_cents' => $amountCents,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => $paidAt ?? now(),
                'period_from' => $desde->toDateString(),
                'period_to' => $hasta->toDateString(),
                'registered_by' => auth('platform')->id(),
            ]);

            $subscription->update([
                'current_period_end' => $hasta,
                'status' => 'active',
            ]);

            // Pagar REACTIVA. Es lo que espera cualquiera que acaba de pagar, y
            // dejarlo para un segundo paso manual es cómo un cliente al día
            // sigue sin poder trabajar el lunes por la mañana.
            $this->setTenantStatus($subscription->tenant_id, TenantStatus::Active);

            $this->audit->record('subscription.payment_registered', $subscription->tenant_id, [
                'amount_cents' => $amountCents,
                'method' => $method,
                'period_to' => $hasta->toDateString(),
            ]);

            return $payment;
        });
    }

    /**
     * Los que están a punto de vencer, para avisarles.
     *
     * Se marca en la bitácora que ya se avisó, y por eso es **idempotente**:
     * correr la escoba dos veces el mismo día no manda el aviso dos veces.
     *
     * Aquí sólo se DECIDE a quién avisar. Mandarlo es cosa del canal —correo,
     * WhatsApp— y no de esto: si mañana el aviso sale por otro sitio, esta
     * lógica no se toca.
     *
     * @return list<array{tenant_id: string, days_left: int, ends_at: string}>
     */
    public function dueForWarning(): array
    {
        $avisos = [];

        foreach (self::AVISOS as $dias) {
            $limite = now()->addDays($dias);

            $subscriptions = SubscriptionModel::query()
                ->whereNull('cancelled_at')
                ->whereBetween('current_period_end', [$limite->copy()->startOfDay(), $limite->copy()->endOfDay()])
                ->get();

            foreach ($subscriptions as $subscription) {
                if ($this->alreadyWarned($subscription->tenant_id, $dias)) {
                    continue;
                }

                $this->audit->record('subscription.warned', $subscription->tenant_id, [
                    'dias' => $dias,
                    'vence' => $subscription->current_period_end->toDateString(),
                ]);

                $avisos[] = [
                    'tenant_id' => (string) $subscription->tenant_id,
                    'days_left' => $dias,
                    'ends_at' => $subscription->current_period_end->toDateString(),
                ];
            }
        }

        return $avisos;
    }

    /**
     * ¿Ya se avisó de este vencimiento?
     *
     * Se mira la bitácora en vez de guardar una columna `warned_at`: la
     * columna habría que limpiarla al renovar, y el día que se olvide, el
     * cliente deja de recibir avisos para siempre sin que nadie lo note.
     */
    private function alreadyWarned(string $tenantId, int $dias): bool
    {
        return DB::table('platform_audit_log')
            ->where('tenant_id', $tenantId)
            ->where('action', 'subscription.warned')
            ->where('created_at', '>=', now()->subHours(20))
            ->whereRaw("details->>'dias' = ?", [(string) $dias])
            ->exists();
    }

    /**
     * Pasa la escoba: marca los vencidos y suspende a los que agotaron la
     * gracia.
     *
     * Se llama desde el trabajo diario, y es **idempotente**: correrlo dos
     * veces el mismo día no adelanta ni una hora ninguna suspensión.
     *
     * @return array{past_due: int, suspended: int}
     */
    public function sweep(): array
    {
        $vencidas = 0;
        $suspendidas = 0;

        SubscriptionModel::query()
            ->whereNull('cancelled_at')
            ->where('current_period_end', '<', now())
            ->orderBy('current_period_end')
            ->chunkById(100, function ($subscriptions) use (&$vencidas, &$suspendidas): void {
                foreach ($subscriptions as $subscription) {
                    $seAcabaLaGracia = Carbon::instance($subscription->suspendsAt())->isPast();

                    if ($seAcabaLaGracia) {
                        if ($subscription->status !== 'suspended') {
                            $subscription->update(['status' => 'suspended']);
                            $this->setTenantStatus($subscription->tenant_id, TenantStatus::Suspended);
                            $this->audit->record('subscription.suspended', $subscription->tenant_id, [
                                'vencia' => $subscription->current_period_end->toDateString(),
                            ]);
                            $suspendidas++;
                        }

                        continue;
                    }

                    if ($subscription->status !== 'past_due') {
                        $subscription->update(['status' => 'past_due']);
                        $this->setTenantStatus($subscription->tenant_id, TenantStatus::PastDue);
                        $this->audit->record('subscription.past_due', $subscription->tenant_id, [
                            'vencio' => $subscription->current_period_end->toDateString(),
                            'suspende' => $subscription->suspendsAt()->format('Y-m-d'),
                        ]);
                        $vencidas++;
                    }
                }
            });

        return ['past_due' => $vencidas, 'suspended' => $suspendidas];
    }

    /** Cambiar de plan. El período que ya pagó no se toca. */
    public function changePlan(SubscriptionModel $subscription, string $planCode): void
    {
        $anterior = $subscription->plan_code;

        DB::transaction(function () use ($subscription, $planCode): void {
            $subscription->update(['plan_code' => $planCode]);
            DB::table('tenants')->where('id', $subscription->tenant_id)->update([
                'plan_code' => $planCode,
                'updated_at' => now(),
            ]);
        });

        $this->forgetTenantCache($subscription->tenant_id);

        $this->audit->record('subscription.plan_changed', $subscription->tenant_id, [
            'de' => $anterior,
            'a' => $planCode,
        ]);
    }

    /**
     * Cambia el estado del negocio **y limpia su caché**.
     *
     * Las dos cosas, siempre juntas. Si se olvida la segunda, el negocio
     * suspendido sigue trabajando hasta que la caché expire —o al revés, el que
     * acaba de pagar sigue bloqueado— y el fallo no deja rastro en ningún sitio.
     */
    public function setTenantStatus(string $tenantId, TenantStatus $status): void
    {
        DB::table('tenants')->where('id', $tenantId)->update([
            'status' => $status->value,
            'updated_at' => now(),
        ]);

        $this->forgetTenantCache($tenantId);
    }

    private function forgetTenantCache(string $tenantId): void
    {
        $slug = DB::table('tenants')->where('id', $tenantId)->value('slug');

        if (is_string($slug)) {
            $this->tenants->forget($slug);
        }
    }
}
