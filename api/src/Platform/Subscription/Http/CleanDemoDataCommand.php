<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Tenancy\TenantSession;

/**
 * Cierra lo que quedó abierto en los negocios de demostración.
 *
 * Las pantallas de trabajo —el tablero del panel y la cocina— muestran lo que
 * está VIVO. En un local de verdad eso se cierra solo: la comida sale, alguien
 * toca «entregado», y el tablero vuelve a estar vacío. En una demostración no:
 * los pedidos de las pruebas de ayer siguen ahí, y al cabo de unas semanas el
 * tablero tiene doscientos pedidos que nadie va a entregar nunca.
 *
 * Esto los cierra. **No borra nada**: los pedidos quedan entregados y las
 * comandas servidas, así que los reportes siguen contándolos.
 *
 * Sólo corre donde las herramientas de demostración están encendidas. En
 * producción, cerrar pedidos de nadie es lo último que queremos.
 */
final class CleanDemoDataCommand extends Command
{
    protected $signature = 'demo:limpiar {--horas=12 : Desde cuántas horas atrás se considera abandonado}';

    protected $description = 'Cierra los pedidos y comandas que quedaron abiertos en los negocios de demostración';

    public function handle(TenantSession $session): int
    {
        if (config('kombo.demo_tools') !== true) {
            $this->error('Las herramientas de demostración están apagadas. Aquí no.');

            return self::FAILURE;
        }

        $limite = now()->subHours((int) $this->option('horas'));
        $total = 0;
        $personas = 0;

        foreach (DB::table('tenants')->whereNull('deleted_at')->pluck('id') as $tenantId) {
            [$cerrados, $dadosDeBaja] = $session->within((string) $tenantId, function () use ($limite): array {
                $pedidos = DB::table('orders')
                    ->whereNotIn('status', ['delivered', 'cancelled'])
                    ->where('placed_at', '<', $limite)
                    ->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);

                DB::table('kitchen_tickets')
                    ->whereNotIn('status', ['served', 'cancelled'])
                    ->where('placed_at', '<', $limite)
                    ->update(['status' => 'served', 'served_at' => now(), 'updated_at' => now()]);

                /*
                 * La gente que suman las pruebas de usuario.
                 *
                 * Ocupa PLAZA del plan, y las plazas tienen techo. Sin esto,
                 * cada corrida deja una cuenta activa más y llega el día en que
                 * el botón «Sumar a alguien» aparece deshabilitado: la prueba
                 * falla por el techo del plan —que está haciendo su trabajo— y
                 * no por lo que venía a comprobar. Se pierde media mañana
                 * buscándolo en el sitio equivocado.
                 *
                 * Por el prefijo `[e2e]`, que es la marca que las pruebas
                 * ponen a todo lo que crean. Nadie de un negocio de verdad se
                 * llama así.
                 *
                 * Se DESACTIVA, no se borra: sus pedidos y sus notas siguen
                 * diciendo quién los hizo.
                 */
                $baja = DB::table('users')
                    ->where('name', 'like', '[e2e]%')
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);

                return [$pedidos, $baja];
            });

            $total += $cerrados;
            $personas += $dadosDeBaja;
        }

        $this->info("Pedidos cerrados: {$total} · Cuentas de prueba dadas de baja: {$personas}");

        return self::SUCCESS;
    }
}
