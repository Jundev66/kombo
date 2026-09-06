<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Illuminate\Console\Command;
use Platform\Subscription\Subscriptions;

/**
 * The daily job that makes expiry dates mean something.
 *
 * Without it `current_period_end` would be decorative — exactly what happened
 * in the previous project, where a `plan_expires_at` nobody read let a tenant
 * that stopped paying operate forever.
 *
 * It warns before cutting off: at seven days and at three.
 */
final class SweepSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:check';

    protected $description = 'Marca los vencidos y suspende a los que agotaron la gracia';

    public function handle(Subscriptions $subscriptions): int
    {
        /*
         * Warn first, then sweep. The other order would move someone due a
         * warning today straight to "overdue", and they would receive the
         * suspension without ever receiving the warning.
         */
        $notified = $subscriptions->dueForWarning();

        foreach ($notified as $notice) {
            $this->line("Avisado: {$notice['tenant_id']} vence en {$notice['days_left']} días");
        }

        ['past_due' => $expired, 'suspended' => $suspended] = $subscriptions->sweep();

        $this->info(sprintf(
            'Avisados: %d · Vencidos: %d · Suspendidos: %d',
            count($notified),
            $expired,
            $suspended,
        ));

        return self::SUCCESS;
    }
}
