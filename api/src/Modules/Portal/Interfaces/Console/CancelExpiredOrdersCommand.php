<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Portal\Application\UseCases\CancelExpiredOrders;
use Platform\Tenancy\TenantSession;

/**
 * Closes orders left waiting on a payment that never arrived.
 *
 * It walks tenant by tenant, entering each: a scheduled task has no request to
 * take the subdomain from, so without entering, RLS returns zero rows —
 * correctly — and the task does nothing while appearing to work.
 */
final class CancelExpiredOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-expired';

    protected $description = 'Cancela los pedidos que se quedaron esperando el comprobante del pago';

    public function handle(TenantSession $session, CancelExpiredOrders $cancel): int
    {
        $tenants = DB::table('tenants')
            ->whereNull('deleted_at')
            ->pluck('id');

        $total = 0;

        foreach ($tenants as $tenantId) {
            // Entering is THREE things — the PostgreSQL parameter, Eloquent's context,
            // and forgetting the previous tenant's capabilities. With only the first,
            // this task finds no orders at all.
            $total += $session->within((string) $tenantId, fn (): int => $cancel->execute());
        }

        $this->info("Pedidos vencidos cerrados: {$total}");

        return self::SUCCESS;
    }
}
