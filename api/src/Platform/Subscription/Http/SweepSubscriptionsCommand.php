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
 *
 * Avisa antes de cortar: a siete días y a tres. Cortarle a alguien sin haberle
 * avisado es la forma más rápida de perder un cliente que sí iba a pagar.
 */
final class SweepSubscriptionsCommand extends Command
{
    protected $signature = 'suscripciones:revisar';

    protected $description = 'Marca los vencidos y suspende a los que agotaron la gracia';

    public function handle(Subscriptions $subscriptions): int
    {
        /*
         * Primero se avisa, después se barre.
         *
         * En este orden y no al revés: barrer primero movería a «vencido» a
         * alguien que hoy tocaba avisar, y ese cliente recibiría la suspensión
         * sin haber recibido nunca el aviso.
         */
        $avisados = $subscriptions->dueForWarning();

        foreach ($avisados as $aviso) {
            $this->line("Avisado: {$aviso['tenant_id']} vence en {$aviso['days_left']} días");
        }

        ['past_due' => $vencidos, 'suspended' => $suspendidos] = $subscriptions->sweep();

        $this->info(sprintf(
            'Avisados: %d · Vencidos: %d · Suspendidos: %d',
            count($avisados),
            $vencidos,
            $suspendidos,
        ));

        return self::SUCCESS;
    }
}
