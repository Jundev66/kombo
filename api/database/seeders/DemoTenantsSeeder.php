<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Platform\Auth\RoleCatalog;
use Platform\Modules\ModuleRegistry;
use Platform\Tenancy\Database\TenantDatabaseGuard;

/**
 * Dos negocios para poder enseñar el sistema y para que corran las pruebas.
 *
 * **Es ADITIVO**: reusa lo que ya existe, no borra nada, y se puede ejecutar
 * cien veces seguidas dejando el sistema igual. Las pruebas de usuario lo
 * llaman antes de cada corrida; si borrara y recreara, cada corrida invalidaría
 * lo que la anterior dejó a medias.
 *
 * Ojo: esto corre como el DUEÑO del esquema, que se salta RLS. Por eso cada
 * consulta que decide algo («¿ya existe este usuario?») filtra por `tenant_id`
 * a mano. El aislamiento ambiental no está puesto aquí.
 */
class DemoTenantsSeeder extends Seeder
{
    private const PASSWORD = 'demo1234';

    public function run(): void
    {
        $this->negocio(
            slug: 'elsazon',
            nombre: 'Arepera El Sazón',
            plan: 'negocio',
            equipo: [
                ['maria@elsazon.test', 'María', 'owner', '1234'],
                ['jose@elsazon.test', 'José', 'manager', '2345'],
                ['ana@elsazon.test', 'Ana', 'counter', '3456'],
                ['carlos@elsazon.test', 'Carlos', 'kitchen', '4567'],
            ],
        );

        // Un negocio SIN caja: sólo vende por el portal. Existe para que las
        // pruebas verifiquen que la caja se puede apagar de verdad —que sus
        // rutas responden 404 y que su entrada no aparece en el menú—.
        $this->negocio(
            slug: 'laesquina',
            nombre: 'Pizzería La Esquina',
            plan: 'inicial',
            equipo: [
                ['pedro@laesquina.test', 'Pedro', 'owner', '1234'],
                ['lucia@laesquina.test', 'Lucía', 'kitchen', '5678'],
            ],
        );
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $equipo
     */
    private function negocio(string $slug, string $nombre, string $plan, array $equipo): void
    {
        // `tenants` y `plans` son tablas de plataforma: se escriben sin negocio
        // en contexto, porque se consultan para AVERIGUAR de qué negocio se
        // habla.
        $tenantId = $this->tenant($slug, $nombre, $plan);

        // A partir de aquí se escriben tablas de NEGOCIO, así que hay que
        // fijar el contexto — igual que hace una petición real.
        //
        // Se podría evitar corriendo el seeder como dueño del esquema, que se
        // salta RLS. No se hace a propósito: un seeder que necesita
        // superusuario esconde exactamente los errores que RLS existe para
        // atrapar, y este mismo código sirve mañana para dar de alta un
        // cliente de verdad desde la aplicación.
        $guard = app(TenantDatabaseGuard::class);
        $guard->apply($tenantId);

        $this->modulos($tenantId, $plan);
        $this->horario($tenantId);
        $this->ajustesDelPortal($tenantId);

        if ($slug === 'elsazon') {
            $this->zonas($tenantId);
        }

        $rolesUsados = array_unique(array_column($equipo, 2));
        $roles = [];

        foreach ($rolesUsados as $code) {
            $roles[$code] = $this->rol($tenantId, $code);
        }

        foreach ($equipo as [$email, $nombrePersona, $rol, $pin]) {
            $userId = $this->usuario($tenantId, $email, $nombrePersona, $pin);

            DB::table('role_user')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roles[$rol],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Se limpia antes de pasar al siguiente negocio: dejar el contexto
        // puesto sería exactamente el fallo que RLS viene a evitar.
        $guard->clear();
    }

    private function tenant(string $slug, string $nombre, string $plan): string
    {
        $existing = DB::table('tenants')->where('slug', $slug)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('tenants')->insert([
            'id' => $id,
            'slug' => $slug,
            'name' => $nombre,
            'plan_code' => $plan,
            'status' => 'active',
            'timezone' => 'America/Caracas',
            'country_code' => 'VE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Enciende lo que el plan incluye. Después manda `tenant_modules`. */
    private function modulos(string $tenantId, string $plan): void
    {
        $delPlan = DB::table('plan_modules')->where('plan_code', $plan)->pluck('module_code')->all();

        foreach ($delPlan as $module) {
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
     * El horario, sin el cual el portal no acepta ni un pedido.
     *
     * Un día sin fila configurada está CERRADO —es el fallo seguro—, así que un
     * negocio de demostración sin horario parecería roto: la carta se ve, y
     * pedir contesta que está cerrado a cualquier hora.
     */
    private function horario(string $tenantId): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            DB::table('business_hours')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'weekday' => $weekday,
                'opens_at' => '08:00',
                // Hasta la una de la madrugada: además de ser el horario de
                // media comida rápida, deja probado el turno que cruza la
                // medianoche.
                'closes_at' => '01:00',
                'is_closed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Sin estos datos el portal no ofrece pago móvil, y hace bien. */
    private function ajustesDelPortal(string $tenantId): void
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

    private function zonas(string $tenantId): void
    {
        $zonas = [
            ['El Centro', 100, 20],
            ['Los Palos Grandes', 200, 30],
            ['La Urbina', 300, 45],
        ];

        foreach ($zonas as $orden => [$nombre, $tarifa, $minutos]) {
            DB::table('delivery_zones')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'name' => $nombre,
                'fee_cents' => $tarifa,
                'estimated_minutes' => $minutos,
                'is_active' => true,
                'sort_order' => $orden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function rol(string $tenantId, string $code): string
    {
        $catalogo = RoleCatalog::get($code);

        $roleId = (string) (DB::table('roles')
            ->where('tenant_id', $tenantId)   // a mano: aquí RLS no filtra
            ->where('code', $code)
            ->value('id') ?? $this->crearRol($tenantId, $code, $catalogo));

        // El dueño no lleva filas de permisos: se resuelve como `['*']`.
        if ($catalogo['is_owner']) {
            return $roleId;
        }

        /*
         * Los permisos se reconcilian SIEMPRE, también si el rol ya existía.
         *
         * Encender un módulo nuevo tiene que dar sus permisos a los roles base
         * del negocio: si no, el mostrador estrena la caja sin poder cobrar y
         * el fallo aparece en el peor sitio —con un cliente delante— y no dice
         * lo que pasa. Es `insertOrIgnore` sobre el único `(rol, permiso)`, así
         * que no duplica ni pisa lo que el dueño haya cambiado a mano.
         */

        // Sólo se conceden los permisos de módulos que este negocio TIENE. Un
        // permiso de un módulo apagado no existiría de todas formas.
        $activos = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('module_code')
            ->all();

        $disponibles = app(ModuleRegistry::class)->permissionsFor($activos);

        foreach ($catalogo['permissions'] as $permission => $requiereAutorizacion) {
            if (! in_array($permission, $disponibles, true)) {
                continue;
            }

            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'permission' => $permission,
                'requires_authorization' => $requiereAutorizacion,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $roleId;
    }

    /**
     * @param  array{name: string, is_owner: bool, permissions: array<string, bool>}  $catalogo
     */
    private function crearRol(string $tenantId, string $code, array $catalogo): string
    {
        $roleId = (string) Str::uuid7();

        DB::table('roles')->insert([
            'id' => $roleId,
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $catalogo['name'],
            'is_system' => true,
            'is_owner' => $catalogo['is_owner'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    }

    private function usuario(string $tenantId, string $email, string $nombre, string $pin): string
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
            'name' => $nombre,
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
