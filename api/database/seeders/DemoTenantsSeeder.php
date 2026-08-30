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
            telefono: '0414-1234567',
            // Naranja de arepera. Distinto del acento del sistema a propósito:
            // así se ve que el color viene del negocio y no del producto.
            color: '#B4451F',
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
            telefono: '0412-7654321',
            // Verde oscuro: el segundo negocio con otra marca, para que se note
            // que cada portal se ve como su dueño y no como el vecino.
            color: '#1F5D3A',
            equipo: [
                ['pedro@laesquina.test', 'Pedro', 'owner', '1234'],
                ['lucia@laesquina.test', 'Lucía', 'kitchen', '5678'],
            ],
        );
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $equipo
     */
    private function negocio(
        string $slug,
        string $nombre,
        string $plan,
        string $telefono,
        string $color,
        array $equipo,
    ): void {
        // `tenants` y `plans` son tablas de plataforma: se escriben sin negocio
        // en contexto, porque se consultan para AVERIGUAR de qué negocio se
        // habla.
        $tenantId = $this->tenant($slug, $nombre, $plan, $telefono, $color);

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

        $roles = $this->roles($tenantId);

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

    private function tenant(string $slug, string $nombre, string $plan, string $telefono, string $color): string
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
            // Con teléfono y color de marca, y no en blanco. Un negocio de
            // demostración sin teléfono deja sin probar el único camino que
            // tiene un cliente cuando su pedido se atasca, y sin color de marca
            // el portal se ve como el de cualquiera — que es justo lo que el
            // dueño quiere que NO pase.
            'phone' => $telefono,
            'brand_color' => $color,
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

    /**
     * Crea los roles base con sus permisos y devuelve sus identificadores.
     *
     * La reconciliación es la MISMA que usan el alta de un negocio y
     * `roles:reconciliar`, no una copia: cuando eran tres copias, ampliar el
     * catálogo servía a unas y a otras no, y la diferencia sólo se notaba
     * cuando alguien no podía hacer su trabajo.
     *
     * @return array<string, string> código del rol → identificador
     */
    private function roles(string $tenantId): array
    {
        app(RoleProvisioner::class)->reconcile($tenantId);

        return DB::table('roles')
            ->where('tenant_id', $tenantId)   // a mano: aquí RLS no filtra
            ->pluck('id', 'code')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
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
