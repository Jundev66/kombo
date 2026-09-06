<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Platform\Auth\RoleProvisioner;
use Platform\Tenancy\Database\TenantDatabaseGuard;

/**
 * Two tenants, so the system can be demonstrated and the tests can run.
 *
 * ADDITIVE: it reuses what exists, deletes nothing, and runs a hundred times in
 * a row unchanged — the end-to-end tests call it before every run.
 *
 * It runs as the schema OWNER, which bypasses RLS, so every query that decides
 * something filters by `tenant_id` by hand.
 */
class DemoTenantsSeeder extends Seeder
{
    private const PASSWORD = 'demo1234';

    public function run(): void
    {
        $this->tenant(
            slug: 'elsazon',
            name: 'Arepera El Sazón',
            plan: 'business',
            phone: '0414-1234567',
            // Arepera orange, deliberately different from the system accent: it shows
            // the colour comes from the tenant, not from the product.
            color: '#B4451F',
            team: [
                ['maria@elsazon.test', 'María', 'owner', '1234'],
                ['jose@elsazon.test', 'José', 'manager', '2345'],
                ['ana@elsazon.test', 'Ana', 'counter', '3456'],
                ['carlos@elsazon.test', 'Carlos', 'kitchen', '4567'],
            ],
        );

        // A tenant with NO till: portal only. It exists so the tests can verify the
        // till really switches off — routes answer 404 and the menu entry is gone.
        $this->tenant(
            slug: 'laesquina',
            name: 'Pizzería La Esquina',
            plan: 'starter',
            phone: '0412-7654321',
            // Dark green: the second tenant with a different brand, so it is obvious
            // each portal looks like its owner and not like the neighbour.
            color: '#1F5D3A',
            team: [
                ['pedro@laesquina.test', 'Pedro', 'owner', '1234'],
                ['lucia@laesquina.test', 'Lucía', 'kitchen', '5678'],
            ],
        );
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $team
     */
    private function tenant(
        string $slug,
        string $name,
        string $plan,
        string $phone,
        string $color,
        array $team,
    ): void {
        // `tenants` and `plans` are platform tables: written with no tenant in
        // context, because they are queried to find out which tenant it is.
        $tenantId = $this->tenantRow($slug, $name, $plan, $phone, $color);

        // From here on TENANT tables are written, so the context has to be set —
        // exactly as a real request does.
        // This could be avoided by running as the schema owner, which bypasses RLS.
        // Deliberately not: a seeder needing a superuser hides exactly the bugs RLS
        // exists to catch, and this same code will sign up a real customer.
        $guard = app(TenantDatabaseGuard::class);
        $guard->apply($tenantId);

        $this->modules($tenantId, $plan);
        $this->openingHours($tenantId);
        $this->portalSettings($tenantId);

        if ($slug === 'elsazon') {
            $this->zones($tenantId);
        }

        $roles = $this->roles($tenantId);

        foreach ($team as [$email, $personName, $role, $pin]) {
            $userId = $this->user($tenantId, $email, $personName, $pin);

            DB::table('role_user')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roles[$role],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Cleared before the next tenant: leaving the context set would be exactly
        // the failure RLS is there to prevent.
        $guard->clear();
    }

    private function tenantRow(string $slug, string $name, string $plan, string $phone, string $color): string
    {
        $existing = DB::table('tenants')->where('slug', $slug)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('tenants')->insert([
            'id' => $id,
            'slug' => $slug,
            'name' => $name,
            'plan_code' => $plan,
            'status' => 'active',
            // With a phone number and a brand colour, not blank: no phone leaves
            // untested the only route a customer has when their order sticks, and no
            // colour makes the portal look like anybody's.
            'phone' => $phone,
            'brand_color' => $color,
            'timezone' => 'America/Caracas',
            'country_code' => 'VE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Switches on what the plan includes. After that, `tenant_modules` rules. */
    private function modules(string $tenantId, string $plan): void
    {
        $fromPlan = DB::table('plan_modules')->where('plan_code', $plan)->pluck('module_code')->all();

        foreach ($fromPlan as $module) {
            DB::table('tenant_modules')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'module_code' => $module,
                'enabled' => true,
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Opening hours, without which the portal takes no orders at all.
     *
     * An unconfigured day is CLOSED — the safe failure — so a demo tenant with
     * no hours would look broken: the menu shows and ordering says closed.
     */
    private function openingHours(string $tenantId): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            DB::table('business_hours')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'weekday' => $weekday,
                'opens_at' => '08:00',
                // Until one in the morning: half of fast food closes then, and it leaves
                // the shift that crosses midnight tested.
                'closes_at' => '01:00',
                'is_closed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Without this data the portal offers no mobile payment, and rightly so. */
    private function portalSettings(string $tenantId): void
    {
        DB::table('tenant_settings')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'key' => 'portal.pago_movil_details',
            'value' => 'Banco de Venezuela · 0102 · C.I. V-12.345.678 · 0414-1234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function zones(string $tenantId): void
    {
        $zones = [
            ['El Centro', 100, 20],
            ['Los Palos Grandes', 200, 30],
            ['La Urbina', 300, 45],
        ];

        foreach ($zones as $order => [$name, $fee, $minutes]) {
            DB::table('delivery_zones')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'name' => $name,
                'fee_cents' => $fee,
                'estimated_minutes' => $minutes,
                'is_active' => true,
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Creates the base roles with their permissions and returns their ids.
     *
     * The SAME reconciliation used by sign-up and by `roles:reconcile`: when
     * there were three copies, widening the catalog served some and not others.
     *
     * @return array<string, string> role code → id
     */
    private function roles(string $tenantId): array
    {
        app(RoleProvisioner::class)->reconcile($tenantId);

        return DB::table('roles')
            ->where('tenant_id', $tenantId)   // by hand: RLS does not filter here
            ->pluck('id', 'code')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function user(string $tenantId, string $email, string $name, string $pin): string
    {
        $existing = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('users')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'pin_hash' => Hash::make($pin),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
