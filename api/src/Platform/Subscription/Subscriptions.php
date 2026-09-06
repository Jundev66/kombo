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
 * The billing lifecycle: start, charge, expire, suspend.
 *
 * All the date arithmetic lives here and nowhere else. It is short and dull,
 * which is exactly why it would otherwise be duplicated across three screens.
 */
final class Subscriptions
{
    /**
     * How many days before expiry a warning goes out.
     *
     * Twice, not five times: a warning that arrives daily stops being read, and
     * the one that matters gets lost among the others.
     *
     * @var list<int>
     */
    private const AVISOS = [7, 3];

    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly TenantResolver $tenants,
    ) {}

    /**
     * Starts a new tenant's subscription, with a trial if the plan has one.
     *
     * It starts PAID UP TO the end of the trial rather than with no date: a
     * tenant with no `current_period_end` is one the daily job cannot judge.
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
     * Records a payment and EXTENDS the period.
     *
     * From the current expiry if it has not passed, from today if it has. Whoever
     * pays three days early does not lose those days, and whoever pays ten days
     * late does not buy ten days they already lived.
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
            $from = Carbon::instance($subscription->current_period_end)->isFuture()
                ? Carbon::instance($subscription->current_period_end)
                : now();

            $until = $from->copy()->addMonths(max($months, 1));

            $payment = SubscriptionPaymentModel::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'amount_cents' => $amountCents,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => $paidAt ?? now(),
                'period_from' => $from->toDateString(),
                'period_to' => $until->toDateString(),
                'registered_by' => auth('platform')->id(),
            ]);

            $subscription->update([
                'current_period_end' => $until,
                'status' => 'active',
            ]);

            // Paying REACTIVATES. It is what anyone who has just paid expects, and
            // leaving it to a manual second step is how an up-to-date customer still
            // cannot work on Monday morning.
            $this->setTenantStatus($subscription->tenant_id, TenantStatus::Active);

            $this->audit->record('subscription.payment_registered', $subscription->tenant_id, [
                'amount_cents' => $amountCents,
                'method' => $method,
                'period_to' => $until->toDateString(),
            ]);

            return $payment;
        });
    }

    /**
     * Those about to expire, to warn them.
     *
     * The audit log records that a warning went out, which makes this
     * idempotent: running the sweep twice in a day does not warn twice.
     *
     * It only DECIDES who to warn. Sending is the channel's job, so if the
     * warning goes out somewhere else tomorrow, this does not change.
     *
     * @return list<array{tenant_id: string, days_left: int, ends_at: string}>
     */
    public function dueForWarning(): array
    {
        $notices = [];

        foreach (self::AVISOS as $days) {
            $limit = now()->addDays($days);

            $subscriptions = SubscriptionModel::query()
                ->whereNull('cancelled_at')
                ->whereBetween('current_period_end', [$limit->copy()->startOfDay(), $limit->copy()->endOfDay()])
                ->get();

            foreach ($subscriptions as $subscription) {
                if ($this->alreadyWarned($subscription->tenant_id, $days)) {
                    continue;
                }

                $this->audit->record('subscription.warned', $subscription->tenant_id, [
                    'dias' => $days,
                    'vence' => $subscription->current_period_end->toDateString(),
                ]);

                $notices[] = [
                    'tenant_id' => (string) $subscription->tenant_id,
                    'days_left' => $days,
                    'ends_at' => $subscription->current_period_end->toDateString(),
                ];
            }
        }

        return $notices;
    }

    /**
     * Has this expiry already been warned about?
     *
     * The audit log is checked rather than keeping a `warned_at` column: the
     * column would need clearing on renewal, and the day that is forgotten the
     * customer stops getting warnings forever with nobody noticing.
     */
    private function alreadyWarned(string $tenantId, int $days): bool
    {
        return DB::table('platform_audit_log')
            ->where('tenant_id', $tenantId)
            ->where('action', 'subscription.warned')
            ->where('created_at', '>=', now()->subHours(20))
            ->whereRaw("details->>'dias' = ?", [(string) $days])
            ->exists();
    }

    /**
     * Sweeps: marks the overdue and suspends those out of grace.
     *
     * Called from the daily job, and idempotent: running it twice in a day does
     * not bring any suspension forward by an hour.
     *
     * @return array{past_due: int, suspended: int}
     */
    public function sweep(): array
    {
        $expired = 0;
        $suspendedOnes = 0;

        SubscriptionModel::query()
            ->whereNull('cancelled_at')
            ->where('current_period_end', '<', now())
            ->orderBy('current_period_end')
            ->chunkById(100, function ($subscriptions) use (&$expired, &$suspendedOnes): void {
                foreach ($subscriptions as $subscription) {
                    $graceEnds = Carbon::instance($subscription->suspendsAt())->isPast();

                    if ($graceEnds) {
                        if ($subscription->status !== 'suspended') {
                            $subscription->update(['status' => 'suspended']);
                            $this->setTenantStatus($subscription->tenant_id, TenantStatus::Suspended);
                            $this->audit->record('subscription.suspended', $subscription->tenant_id, [
                                'vencia' => $subscription->current_period_end->toDateString(),
                            ]);
                            $suspendedOnes++;
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
                        $expired++;
                    }
                }
            });

        return ['past_due' => $expired, 'suspended' => $suspendedOnes];
    }

    /** Changing plan. The period already paid for is untouched. */
    public function changePlan(SubscriptionModel $subscription, string $planCode): void
    {
        $previous = $subscription->plan_code;

        DB::transaction(function () use ($subscription, $planCode): void {
            $subscription->update(['plan_code' => $planCode]);
            DB::table('tenants')->where('id', $subscription->tenant_id)->update([
                'plan_code' => $planCode,
                'updated_at' => now(),
            ]);
        });

        $this->forgetTenantCache($subscription->tenant_id);

        $this->audit->record('subscription.plan_changed', $subscription->tenant_id, [
            'de' => $previous,
            'a' => $planCode,
        ]);
    }

    /**
     * Changes the tenant's status AND clears its cache.
     *
     * Always both. Forget the second and a suspended tenant keeps working until
     * the cache expires — or one that has just paid stays blocked — and the
     * failure leaves no trace anywhere.
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
