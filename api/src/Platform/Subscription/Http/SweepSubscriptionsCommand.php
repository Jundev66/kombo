<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Illuminate\Console\Command;
use Platform\Subscription\Subscriptions;

/**
 * El trabajo diario que hace que los vencimientos signifiquen algo.
 *
 * Sin esto, `current_period_end` sería una fecha decorativa — que es
 * exactamente lo que pasó en el proyecto anterior: había un `plan_expires_at`
 * que no leía nadie, así que un negocio que dejó de pagar seguía operando para
 * siempre.
 */
final class SweepSubscriptionsCommand extends Command
{
    protected $signature = 'suscripciones:revisar';

    protected $description = 'Marca los vencidos y suspende a los que agotaron la gracia';

    public function handle(Subscriptions $subscriptions): int
    {
        ['past_due' => $vencidos, 'suspended' => $suspendidos] = $subscriptions->sweep();

        $this->info("Vencidos: {$vencidos} · Suspendidos: {$suspendidos}");

        return self::SUCCESS;
    }
}
