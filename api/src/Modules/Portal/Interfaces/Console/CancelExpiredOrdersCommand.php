<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Portal\Application\UseCases\CancelExpiredOrders;
use Platform\Tenancy\Database\TenantDatabaseGuard;

/**
 * Cierra los pedidos que se quedaron esperando un pago que no llegó.
 *
 * **Recorre negocio por negocio, fijando el contexto en cada uno.** No hay
 * atajo: una tarea programada no tiene petición HTTP de la que sacar el
 * subdominio, así que si no se fija el contexto a mano, RLS devuelve cero filas
 * —correctamente— y la tarea no haría nada mientras parece que sí.
 */
final class CancelExpiredOrdersCommand extends Command
{
    protected $signature = 'pedidos:cerrar-vencidos';

    protected $description = 'Cancela los pedidos que se quedaron esperando el comprobante del pago';

    public function handle(TenantDatabaseGuard $guard, CancelExpiredOrders $cancel): int
    {
        $tenants = DB::table('tenants')
            ->whereNull('deleted_at')
            ->pluck('id');

        $total = 0;

        foreach ($tenants as $tenantId) {
            $guard->apply((string) $tenantId);

            $total += $cancel->execute();
        }

        $guard->clear();

        $this->info("Pedidos vencidos cerrados: {$total}");

        return self::SUCCESS;
    }
}
