<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * What it takes for the system to boot and be demonstrable.
 *
 * ADDITIVE on purpose: re-seeding deletes nothing and duplicates no rows, so it
 * survives the hundred runs the end-to-end tests put it through.
 *
 * Seeders run as the schema OWNER, which bypasses RLS: a query that decides
 * something has to filter by `tenant_id` by hand.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // The plans ALWAYS: without them there are no ceilings and no modules, and
        // a new tenant gets signed up against a plan that does not exist.
        $this->call(PlanSeeder::class);

        /*
         * Demo tenants only where they belong. In production `db:seed` gets run
         * without thinking, and without this "El Sazón" would turn up among
         * real customers with a password published in this repository.
         */
        if (config('kombo.demo_tools') === true) {
            $this->call(DemoTenantsSeeder::class);
        }

        // After the tenants: it gives a subscription to those without one.
        $this->call(PlatformSeeder::class);
    }
}
