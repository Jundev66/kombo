<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Tenancy\TenantSession;

/**
 * Closes whatever was left open in the demo tenants.
 *
 * The working screens show what is LIVE. In a real shop that clears itself; in
 * a demo it does not, and after a few weeks the board holds two hundred orders
 * nobody will ever deliver.
 *
 * It closes them and deletes nothing, so the reports still count them. It only
 * runs where the demo tooling is switched on.
 */
final class CleanDemoDataCommand extends Command
{
    protected $signature = 'demo:clean {--hours=12 : Desde cuántas horas atrás se considera abandonado}';

    protected $description = 'Cierra los pedidos y comandas que quedaron abiertos en los negocios de demostración';

    public function handle(TenantSession $session): int
    {
        if (config('kombo.demo_tools') !== true) {
            $this->error('Las herramientas de demostración están apagadas. Aquí no.');

            return self::FAILURE;
        }

        $limit = now()->subHours((int) $this->option('hours'));
        $total = 0;
        $people = 0;

        foreach (DB::table('tenants')->whereNull('deleted_at')->pluck('id') as $tenantId) {
            [$closedOnes, $deactivated] = $session->within((string) $tenantId, function () use ($limit): array {
                $orders = DB::table('orders')
                    ->whereNotIn('status', ['delivered', 'cancelled'])
                    ->where('placed_at', '<', $limit)
                    ->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);

                DB::table('kitchen_tickets')
                    ->whereNotIn('status', ['served', 'cancelled'])
                    ->where('placed_at', '<', $limit)
                    ->update(['status' => 'served', 'served_at' => now(), 'updated_at' => now()]);

                /*
                 * The people the end-to-end tests add up.
                 *
                 * They take a SEAT in the plan, and seats have a ceiling.
                 * Without this, every run leaves one more active account until
                 * "Sumar a alguien" is disabled and a test fails on the plan
                 * ceiling doing its job, not on what it meant to check.
                 *
                 * Matched by the `[e2e]` prefix the tests stamp on everything
                 * they create. They are DEACTIVATED, not deleted: their orders
                 * and notes keep saying who made them.
                 */
                $deactivated = DB::table('users')
                    ->where('name', 'like', '[e2e]%')
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);

                return [$orders, $deactivated];
            });

            $total += $closedOnes;
            $people += $deactivated;
        }

        $this->info("Pedidos cerrados: {$total} · Cuentas de prueba dadas de baja: {$people}");

        return self::SUCCESS;
    }
}
