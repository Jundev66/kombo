<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Platform\PlatformUser;
use App\Models\Platform\SubscriptionModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The platform administrator, and each tenant's subscription.
 *
 * Additive, like everything seeded here. Without a subscription there is no
 * `current_period_end`, and the daily job cannot judge that tenant.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * The demo account, ONLY where the demo tooling is switched on: its
         * password is written here, which is to say published. Seeding on a
         * server would otherwise leave open an account that sees every
         * customer's data. In production, use `platform:admin`.
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
                // A month ahead: demo tenants have to be usable without somebody
                // registering a payment first.
                'current_period_end' => now()->addMonth(),
            ]);
        }
    }
}
