<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Portal\Application\UseCases\CancelExpiredOrders;
use Platform\Tenancy\TenantSession;

/**
 * Cierra los pedidos que se quedaron esperando un pago que no llegó.
 *
 * **Recorre negocio por negocio, entrando en cada uno.** No hay atajo: una
 * tarea programada no tiene petición HTTP de la que sacar el subdominio, así
 * que si no se entra a mano, RLS devuelve cero filas —correctamente— y la
 * tarea no haría nada mientras parece que sí.
 */
final class CancelExpiredOrdersCommand extends Command
{
    protected $signature = 'pedidos:cerrar-vencidos';

    protected $description = 'Cancela los pedidos que se quedaron esperando el comprobante del pago';

    public function handle(TenantSession $session, CancelExpiredOrders $cancel): int
    {
        $tenants = DB::table('tenants')
            ->whereNull('deleted_at')
            ->pluck('id');

        $total = 0;

        foreach ($tenants as $tenantId) {
            // Entrar es TRES cosas —el parámetro de PostgreSQL, el contexto de
            // Eloquent y olvidar las capacidades del negocio anterior—, y con
            // sólo la primera esta tarea no encontraría ni un pedido.
            $total += $session->within((string) $tenantId, fn (): int => $cancel->execute());
        }

        $this->info("Pedidos vencidos cerrados: {$total}");

        return self::SUCCESS;
    }
}
