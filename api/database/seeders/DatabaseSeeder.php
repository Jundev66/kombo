<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Lo que hace falta para que el sistema arranque y se pueda enseñar.
 *
 * Es ADITIVO a propósito: volver a sembrar no borra nada ni duplica filas.
 * Las pruebas de usuario lo llaman antes de cada corrida, así que tiene que
 * poder ejecutarse cien veces seguidas sin dejar el sistema distinto.
 *
 * Cuidado al escribir seeders: corren como el DUEÑO del esquema, que se salta
 * RLS. Una consulta que decide algo («¿ya existe?») tiene que filtrar por
 * `tenant_id` a mano — el aislamiento ambiental no está puesto aquí.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Los planes SIEMPRE: sin ellos no hay techos que aplicar ni módulos
        // que encender, y un negocio nuevo se da de alta contra un plan que no
        // existe.
        $this->call(PlanSeeder::class);

        /*
         * Los negocios de demostración sólo donde toca.
         *
         * En producción `db:seed` se corre sin pensar —para rellenar los
         * planes después de una actualización— y sin esta condición aparecería
         * «El Sazón» en la lista de clientes de verdad, con su dueño de mentira
         * y una contraseña publicada en este repositorio.
         */
        if (config('kombo.demo_tools') === true) {
            $this->call(DemoTenantsSeeder::class);
        }

        // Después de los negocios: le da suscripción a los que no la tengan.
        $this->call(PlatformSeeder::class);
    }
}
