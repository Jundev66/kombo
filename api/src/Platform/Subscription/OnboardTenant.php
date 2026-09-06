<?php

declare(strict_types=1);

namespace Platform\Subscription;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Platform\Auth\RoleProvisioner;
use Platform\Tenancy\TenantSession;
use Shared\Domain\Exceptions\UserError;

/**
 * Signing up a new tenant. All in one transaction.
 *
 * A half-created tenant — a `tenants` row with no owner, or an owner with no
 * modules — is worse than none: nobody can get in to fix it, because getting in
 * needs exactly what is missing, and from outside it looks like it exists.
 *
 * It leaves ready: the tenant, its subscription, the plan's modules, the base
 * roles with their permissions, the owner, and opening hours — without which
 * the portal will not take a single order.
 */
final class OnboardTenant
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly PlatformAudit $audit,
        private readonly TenantSession $session,
        private readonly RoleProvisioner $roles,
    ) {}

    /**
     * @return array{tenant_id: string, slug: string}
     */
    public function execute(
        string $name,
        string $slug,
        string $planCode,
        string $ownerName,
        string $ownerEmail,
        string $ownerPassword,
    ): array {
        $slug = Str::lower(trim($slug));

        self::assertSlugIsUsable($slug);

        $tenantId = DB::transaction(function () use (
            $name, $slug, $planCode, $ownerName, $ownerEmail, $ownerPassword
        ): string {
            $tenantId = (string) Str::uuid7();

            DB::table('tenants')->insert([
                'id' => $tenantId,
                'slug' => $slug,
                'name' => $name,
                'plan_code' => $planCode,
                'status' => 'trial',
                'timezone' => 'America/Caracas',
                'country_code' => 'VE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->subscriptions->start($tenantId, $planCode);

            /*
             * From here on TENANT tables are written, so we have to enter it:
             * without context RLS rejects every insert — correctly — and the
             * whole sign-up would fail.
             */
            $this->session->within($tenantId, function () use ($tenantId, $planCode, $ownerName, $ownerEmail, $ownerPassword): void {
                $this->enableModulesOfPlan($tenantId, $planCode);
                $this->seedRoles($tenantId);
                $this->seedOwner($tenantId, $ownerName, $ownerEmail, $ownerPassword);
                $this->seedHours($tenantId);
            });

            return $tenantId;
        });

        $this->audit->record('tenant.created', $tenantId, [
            'slug' => $slug,
            'plan' => $planCode,
            'owner' => $ownerEmail,
        ]);

        return ['tenant_id' => $tenantId, 'slug' => $slug];
    }

    /**
     * Slugs nobody may take.
     *
     * `admin` because that is platform administration, and `www` and company
     * because people type them out of habit.
     */
    private static function assertSlugIsUsable(string $slug): void
    {
        $reserved = ['admin', 'www', 'api', 'app', 'mail', 'ftp', 'kombo', 'soporte', 'ayuda'];

        if (in_array($slug, $reserved, true)) {
            throw new class('Ese nombre de dirección está reservado. Elige otro.') extends UserError
            {
                public function field(): ?string
                {
                    return 'slug';
                }
            };
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) {
            throw new class('La dirección sólo admite letras, números y guiones.') extends UserError
            {
                public function field(): ?string
                {
                    return 'slug';
                }
            };
        }

        if (DB::table('tenants')->where('slug', $slug)->exists()) {
            throw new class('Ya hay un negocio con esa dirección.') extends UserError
            {
                public function field(): ?string
                {
                    return 'slug';
                }
            };
        }
    }

    private function enableModulesOfPlan(string $tenantId, string $planCode): void
    {
        $fromPlan = DB::table('plan_modules')->where('plan_code', $planCode)->pluck('module_code');

        foreach ($fromPlan as $module) {
            DB::table('tenant_modules')->insert([
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
     * The base roles, with the permissions of the modules this tenant HAS.
     *
     * The same reconciliation `roles:reconcile` applies to existing tenants.
     * One place on purpose: when there were two, widening the catalog served
     * new tenants and left the old ones out.
     */
    private function seedRoles(string $tenantId): void
    {
        $this->roles->reconcile($tenantId);
    }

    private function seedOwner(string $tenantId, string $name, string $email, string $password): void
    {
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'id' => $userId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownerRoleId = DB::table('roles')
            ->where('tenant_id', $tenantId)
            ->where('is_owner', true)
            ->value('id');

        DB::table('role_user')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $ownerRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A starting set of opening hours.
     *
     * With no rows the portal is CLOSED — the safe failure — and a brand new
     * tenant would look broken: the menu shows and ordering says it is closed
     * at any hour. Eight to eight, which the owner will change on day one.
     */
    private function seedHours(string $tenantId): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            DB::table('business_hours')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'weekday' => $weekday,
                'opens_at' => '08:00',
                'closes_at' => '20:00',
                'is_closed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
