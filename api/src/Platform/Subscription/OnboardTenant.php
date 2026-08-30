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
 * Dar de alta un negocio nuevo.
 *
 * **Todo en una transacción**, y esto no es celo de programador: un negocio a
 * medio crear —con su fila en `tenants` pero sin dueño, o con dueño y sin
 * módulos— es peor que ninguno. Nadie puede entrar a arreglarlo desde dentro,
 * porque para entrar hace falta justo lo que faltó, y desde fuera parece que
 * existe.
 *
 * Lo que deja listo: el negocio, su suscripción, los módulos que trae el plan,
 * los roles base con sus permisos, el dueño con su contraseña, y el horario —
 * sin el cual el portal no acepta ni un pedido.
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
             * A partir de aquí se escriben tablas del NEGOCIO, así que hay que
             * entrar en él: sin contexto, RLS rechaza cada inserción —
             * correctamente— y el alta fallaría entera.
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
     * Los slugs que no puede tomar nadie.
     *
     * `admin` está reservado porque es la super administración, y `www` y
     * compañía porque son subdominios que la gente escribe por costumbre. Que
     * un cliente se llame `admin` no sería un error bonito de descubrir.
     */
    private static function assertSlugIsUsable(string $slug): void
    {
        $reservados = ['admin', 'www', 'api', 'app', 'mail', 'ftp', 'kombo', 'soporte', 'ayuda'];

        if (in_array($slug, $reservados, true)) {
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
        $delPlan = DB::table('plan_modules')->where('plan_code', $planCode)->pluck('module_code');

        foreach ($delPlan as $module) {
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
     * Los roles base, con los permisos de los módulos que este negocio TIENE.
     *
     * La misma reconciliación que aplica `roles:reconciliar` a los negocios que
     * ya existen. Es un solo sitio a propósito: cuando eran dos, ampliar el
     * catálogo servía a los negocios nuevos y dejaba fuera a los viejos.
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
     * Un horario de partida.
     *
     * Sin filas de horario el portal está CERRADO —es el fallo seguro— y un
     * negocio recién dado de alta parecería roto: la carta se ve y pedir
     * contesta que está cerrado a cualquier hora. Se deja abierto de ocho a
     * ocho, que es lo que el dueño va a cambiar en su primera tarde.
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
