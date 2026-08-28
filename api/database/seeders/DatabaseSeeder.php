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
        $this->call([
            PlanSeeder::class,
            // Fase 1: DemoTenantsSeeder — los negocios de demostración con su
            // dueño, su equipo y un catálogo de verdad.
        ]);
    }
}
