<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Platform\PlatformUser;
use App\Models\Platform\SubscriptionModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El administrador de la plataforma, y la suscripción de cada negocio.
 *
 * **Es aditivo**, como todo lo que se siembra aquí: se puede correr cien veces
 * seguidas y deja el sistema igual.
 *
 * Da de alta una suscripción a los negocios que no la tengan. Sin ella no hay
 * `current_period_end`, y sin esa fecha el trabajo diario no sabe qué hacer con
 * ese negocio — ni suspenderlo ni dejarlo en paz.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * La cuenta de demostración, y SÓLO donde las herramientas de
         * demostración están encendidas.
         *
         * Su contraseña está escrita aquí, o sea publicada en el repositorio.
         * Sin esta condición, sembrar en un servidor —algo que se hace sin
         * pensar, para rellenar los planes— dejaría abierta una cuenta que ve
         * los datos de todos los clientes.
         *
         * En producción el administrador se crea con `plataforma:admin`.
         */
        if (config('kombo.demo_tools') === true) {
            PlatformUser::firstOrCreate(
                ['email' => 'admin@kombo.test'],
                [
                    'name' => 'Administración',
                    'password' => 'demo1234',
                    'is_active' => true,
                ],
            );
        }

        $tenants = DB::table('tenants')->whereNull('deleted_at')->get(['id', 'plan_code']);

        foreach ($tenants as $tenant) {
            if (SubscriptionModel::where('tenant_id', $tenant->id)->exists()) {
                continue;
            }

            SubscriptionModel::create([
                'tenant_id' => $tenant->id,
                'plan_code' => $tenant->plan_code,
                'status' => 'active',
                'started_at' => now(),
                // Un mes por delante: los negocios de demostración tienen que
                // poder usarse sin que nadie registre un pago primero.
                'current_period_end' => now()->addMonth(),
            ]);
        }
    }
}
