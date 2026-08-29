<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los planes.
 *
 * Se cobra por TAMAÑO, no por funcionalidad básica: ningún negocio se queda
 * sin saber cuánto vendió porque no le alcanza. Lo que separa los planes es
 * cuántos usuarios, cuántos productos y cuántos pedidos al mes — más los
 * módulos que aportan capacidades nuevas (la caja, los canales).
 *
 * Es aditivo (`upsert`): volver a sembrar no pisa lo que un negocio ya tiene
 * ni duplica filas.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // `null` significa ILIMITADO. Nunca cero: cero es "ninguno", que es
        // una respuesta distinta y mucho peor de depurar.
        $plans = [
            [
                'code' => 'inicial',
                'name' => 'Inicial',
                'description' => 'Para empezar a dejar el cuaderno hoy, sin pagar nada.',
                'sort_order' => 1,
                'price_cents' => 0,
                'max_users' => 2,
                'max_products' => 60,
                'max_orders_month' => 300,
                'trial_days' => null,
                // El portal va también en el plan gratis: es cómo un negocio
                // pequeño empieza a recibir pedidos, y cobrarlo aparte sería
                // cobrar por lo básico.
                'modules' => ['catalog', 'orders', 'kitchen', 'portal'],
            ],
            [
                'code' => 'negocio',
                'name' => 'Negocio',
                'description' => 'El local completo: caja, portal de pedidos y WhatsApp.',
                'sort_order' => 2,
                'price_cents' => 2500,
                'max_users' => 8,
                'max_products' => null,
                'max_orders_month' => null,
                'trial_days' => 30,
                'modules' => ['catalog', 'orders', 'kitchen', 'portal', 'counter', 'documents', 'delivery', 'channels', 'reports', 'customers'],
            ],
            [
                'code' => 'completo',
                'name' => 'Completo',
                'description' => 'Sin límites de equipo, con reportes comparativos.',
                'sort_order' => 3,
                'price_cents' => 6000,
                'max_users' => null,
                'max_products' => null,
                'max_orders_month' => null,
                'trial_days' => 30,
                'modules' => ['catalog', 'orders', 'kitchen', 'portal', 'counter', 'documents', 'delivery', 'channels', 'reports', 'customers'],
            ],
        ];

        $now = now();

        foreach ($plans as $plan) {
            $modules = $plan['modules'];
            unset($plan['modules']);

            DB::table('plans')->upsert(
                [[...$plan, 'currency' => 'USD', 'is_public' => true, 'created_at' => $now, 'updated_at' => $now]],
                ['code'],
                array_keys($plan),
            );

            foreach ($modules as $module) {
                DB::table('plan_modules')->insertOrIgnore([
                    'plan_code' => $plan['code'],
                    'module_code' => $module,
                ]);
            }
        }
    }
}
