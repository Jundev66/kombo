<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The plans.
 *
 * You pay for SIZE, not for basic functionality. What separates them is how
 * many users, products and orders a month — plus the modules that add new
 * capabilities. Additive (`upsert`), so re-seeding overwrites nothing.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // `null` means UNLIMITED. Never zero: zero is "none", a different answer
        // and far worse to debug.
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Inicial',
                'description' => 'Para empezar a dejar el cuaderno hoy, sin pagar nada.',
                'sort_order' => 1,
                'price_cents' => 0,
                'max_users' => 2,
                'max_products' => 60,
                'max_orders_month' => 300,
                'trial_days' => null,
                // The portal is in the free plan too: it is how a small tenant starts
                // taking orders, and charging for it would be charging for the basics.
                'modules' => ['catalog', 'orders', 'kitchen', 'portal'],
            ],
            [
                'code' => 'business',
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
                'code' => 'complete',
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
