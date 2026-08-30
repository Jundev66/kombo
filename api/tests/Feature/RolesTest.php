<?php

declare(strict_types=1);

/*
 * Que ampliar el catálogo de roles llegue a alguien.
 *
 * Hasta que existió `roles:reconciliar`, `role_permissions` sólo se escribía al
 * dar de alta un negocio. Darle el horario al encargado servía a los negocios
 * que se registraran a partir de ese despliegue y a nadie más: el código salía,
 * no fallaba nada, y el encargado de un local de hace seis meses seguía sin
 * poder. Un cambio que no rompe y tampoco hace nada tarda meses en descubrirse.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Auth\RoleProvisioner;

beforeEach(function (): void {
    $this->slug = 'reparto-'.Str::lower(Str::random(6));
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);

    foreach (['catalog', 'orders', 'counter', 'channels'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }
});

/** Los permisos que tiene un rol base de este negocio, ahora mismo. */
function permisosDe(string $tenantId, string $code): array
{
    return DB::table('role_permissions')
        ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
        ->where('roles.tenant_id', $tenantId)
        ->where('roles.code', $code)
        ->orderBy('permission')
        ->pluck('permission')
        ->all();
}

it('los permisos del núcleo llegan al encargado, aunque el núcleo no esté en tenant_modules', function (): void {
    /*
     * Ésta es la prueba de un fallo que estuvo vivo mucho tiempo y no se veía.
     *
     * `core` no depende del plan y no se apaga, así que nunca tiene fila en
     * `tenant_modules`. La siembra leía sólo esa tabla, de modo que
     * `settings.manage`, `users.manage` y `audit.view` no llegaban a ningún rol
     * que no fuera el dueño — y el síntoma era un encargado que no podía tocar
     * el horario sin ningún error que lo explicara.
     */
    app(RoleProvisioner::class)->reconcile($this->tenant);

    expect(permisosDe($this->tenant, 'manager'))
        ->toContain('settings.manage')
        ->toContain('users.manage')
        ->toContain('audit.view');
});

it('un negocio de antes recupera los permisos que le faltaban', function (): void {
    app(RoleProvisioner::class)->reconcile($this->tenant);

    // Así estaba el negocio dado de alta con el catálogo viejo.
    DB::table('role_permissions')
        ->whereIn('role_id', DB::table('roles')->where('tenant_id', $this->tenant)->where('code', 'manager')->pluck('id'))
        ->whereIn('permission', ['settings.manage', 'users.manage'])
        ->delete();

    expect(permisosDe($this->tenant, 'manager'))->not->toContain('settings.manage');

    $this->artisan('roles:reconciliar', ['--negocio' => $this->slug])->assertSuccessful();

    expect(permisosDe($this->tenant, 'manager'))
        ->toContain('settings.manage')
        ->toContain('users.manage');
});

it('correrlo dos veces no duplica ni una fila', function (): void {
    $this->artisan('roles:reconciliar', ['--negocio' => $this->slug])->assertSuccessful();

    $primera = permisosDe($this->tenant, 'manager');

    $this->artisan('roles:reconciliar', ['--negocio' => $this->slug])->assertSuccessful();

    expect(permisosDe($this->tenant, 'manager'))->toBe($primera);
});

it('un rol de sistema vuelve a lo que dice el catálogo', function (): void {
    /*
     * Sólo añade filas, nunca borra ninguna — pero eso NO quiere decir «respeta
     * lo que hayas tocado a mano»: lo que falta, vuelve.
     *
     * Es lo correcto para los roles base, que son `is_system` y no se editan
     * desde ninguna pantalla: su contenido lo decide el catálogo. Si algún día
     * el dueño puede quitarle un permiso suelto a su encargado, hará falta
     * guardar esa decisión en algún sitio — un borrado no es una decisión, es
     * una fila que no está.
     */
    app(RoleProvisioner::class)->reconcile($this->tenant);

    $manager = DB::table('roles')->where('tenant_id', $this->tenant)->where('code', 'manager')->value('id');

    DB::table('role_permissions')
        ->where('role_id', $manager)
        ->where('permission', 'catalog.change_price')
        ->delete();

    $this->artisan('roles:reconciliar', ['--negocio' => $this->slug])->assertSuccessful();

    expect(permisosDe($this->tenant, 'manager'))->toContain('catalog.change_price');
});

it('un rol propio del negocio ni se mira', function (): void {
    // Sólo se tocan los códigos que están en el catálogo. Uno que el negocio se
    // haya creado encima no es asunto de esto.
    app(RoleProvisioner::class)->reconcile($this->tenant);

    $propio = (string) Str::uuid7();

    DB::table('roles')->insert([
        'id' => $propio,
        'tenant_id' => $this->tenant,
        'code' => 'fin_de_semana',
        'name' => 'Fin de semana',
        'is_system' => false,
        'is_owner' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_permissions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'role_id' => $propio,
        'permission' => 'orders.view',
        'requires_authorization' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('roles:reconciliar', ['--negocio' => $this->slug])->assertSuccessful();

    expect(permisosDe($this->tenant, 'fin_de_semana'))->toBe(['orders.view']);
});

it('no reparte permisos de un módulo que el negocio no tiene', function (): void {
    // `delivery` no está encendido en este negocio, así que sus permisos no
    // existen en el sistema y escribir la fila sería escribir algo que no
    // significa nada.
    app(RoleProvisioner::class)->reconcile($this->tenant);

    expect(permisosDe($this->tenant, 'manager'))
        ->not->toContain('delivery.manage')
        ->toContain('counter.sell');
});

it('el plan es el techo: un módulo encendido que el plan ya no incluye no reparte nada', function (): void {
    /*
     * Es la misma cuenta que hace `CapabilityResolver`, y tiene que serlo: un
     * permiso concedido aquí que allí no se resuelve es una fila que existe y
     * no sirve, y explica muy mal por qué algo «que está» no funciona.
     */
    $slug = 'inicial-'.Str::lower(Str::random(6));
    $pequeno = makeTenant($slug, plan: 'inicial');

    actingForTenant($pequeno);
    enableModule($pequeno, 'catalog');
    // El plan inicial no incluye la caja. La fila existe; el techo manda.
    enableModule($pequeno, 'counter');

    app(RoleProvisioner::class)->reconcile($pequeno);

    expect(permisosDe($pequeno, 'manager'))
        ->not->toContain('counter.sell')
        ->toContain('catalog.manage');
});

it('el dueño no lleva filas de permisos', function (): void {
    // Se resuelve como `['*']` y se expande contra los módulos encendidos HOY.
    // Guardárselos uno a uno significaría que al encender un módulo nuevo no
    // podría usarlo hasta que alguien se acordara de añadírselos.
    app(RoleProvisioner::class)->reconcile($this->tenant);

    expect(permisosDe($this->tenant, 'owner'))->toBe([]);
});
