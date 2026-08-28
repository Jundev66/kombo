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
        PlatformUser::firstOrCreate(
            ['email' => 'admin@kombo.test'],
            [
                'name' => 'Administración',
                // Es una demostración, y la contraseña es la misma que la de
                // los negocios de prueba a propósito: en producción esta cuenta
                // se crea a mano y con otra.
                'password' => 'demo1234',
                'is_active' => true,
            ],
        );

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
