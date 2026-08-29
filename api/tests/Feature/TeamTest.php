<?php

declare(strict_types=1);

/*
 * El equipo del negocio.
 *
 * Es lo que permite que un local crezca de una persona a cinco sin que nadie
 * toque la base de datos. Y es donde el techo del plan significa algo: un
 * límite que sólo se enseña en una pantalla de administración no es un límite.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Platform\Auth\RoleCatalog;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    // Los roles base tienen que existir para poder repartirlos. Se crean
    // directamente y no con `giveRole`, que además crea un usuario: aquí lo
    // que hace falta son los roles, no gente de relleno ocupando el plan.
    foreach (['manager', 'counter', 'kitchen'] as $code) {
        $catalogo = RoleCatalog::get($code);

        DB::table('roles')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant,
            'code' => $code,
            'name' => $catalogo['name'],
            'is_system' => true,
            'is_owner' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

/** Alguien del equipo con un rol que YA existe en el negocio. */
function conRol(string $tenantId, string $email, string $name, string $code): string
{
    $userId = makeUser($tenantId, $email, $name);

    DB::table('role_user')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role_id' => DB::table('roles')->where('code', $code)->value('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $userId;
}

function equipo(string $slug, string $method = 'GET', string $path = '', array $body = []): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->json($method, urlFor($slug, "/api/v1/team{$path}"), $body);
}

it('el dueño suma a alguien a su equipo, y esa persona entra', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    equipo($this->slug, 'POST', '', [
        'name' => 'José',
        'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123',
        'role_code' => 'manager',
        'pin' => '2345',
    ])->assertCreated();

    // Y entra de verdad: si la contraseña se guardara mal —hasheada dos veces,
    // por ejemplo— esto fallaría y el fallo no diría por qué.
    entrarComo($this->slug, 'jose@ejemplo.com', 'clave-larga-123');

    actingForTenant($this->tenant);

    $jose = User::where('email', 'jose@ejemplo.com')->first();

    expect($jose->roles->first()?->code)->toBe('manager')
        // Y su PIN sirve para la caja: guardarlo hasheado dos veces sería un
        // PIN que nunca abre nada.
        ->and(Hash::check('2345', (string) $jose->pin_hash))->toBeTrue();
});

it('el techo del plan se aplica AL CREAR, no en un informe', function (): void {
    // El plan inicial llega a 2 personas.
    $sufijo = Str::lower(Str::random(6));
    $slug = "pequeno-{$sufijo}";
    $tenantId = makeTenant($slug, plan: 'inicial');

    actingForTenant($tenantId);
    enableModule($tenantId, 'core');

    $duena = makeUser($tenantId, 'duena@ejemplo.com', 'Dueña');
    giveRole($tenantId, $duena, 'owner');
    giveRole($tenantId, makeUser($tenantId, 'otro@ejemplo.com', 'Otro'), 'kitchen');

    entrarComo($slug, 'duena@ejemplo.com');

    $respuesta = equipo($slug, 'POST', '', [
        'name' => 'Tercero',
        'email' => 'tercero@ejemplo.com',
        'password' => 'clave-larga-123',
        'role_code' => 'kitchen',
    ])->assertStatus(422);

    // Y el mensaje dice qué hacer, no sólo que no se puede.
    expect($respuesta->json('message'))->toContain('subir de plan');
});

it('quien está dado de baja no ocupa una plaza que se paga', function (): void {
    $sufijo = Str::lower(Str::random(6));
    $slug = "recicla-{$sufijo}";
    $tenantId = makeTenant($slug, plan: 'inicial');

    actingForTenant($tenantId);
    enableModule($tenantId, 'core');

    $duena = makeUser($tenantId, 'duena@ejemplo.com', 'Dueña');
    giveRole($tenantId, $duena, 'owner');

    $viejo = makeUser($tenantId, 'viejo@ejemplo.com', 'Viejo');
    giveRole($tenantId, $viejo, 'kitchen');

    entrarComo($slug, 'duena@ejemplo.com');

    // Con dos activos, el plan inicial está lleno.
    equipo($slug, 'POST', '', [
        'name' => 'Nuevo', 'email' => 'nuevo@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'kitchen',
    ])->assertStatus(422);

    equipo($slug, 'DELETE', "/{$viejo}")->assertStatus(204);

    // Ahora sí: alguien que se fue hace tres meses no puede seguir costando.
    equipo($slug, 'POST', '', [
        'name' => 'Nuevo', 'email' => 'nuevo@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'kitchen',
    ])->assertCreated();
});

it('dar de baja DESACTIVA, no borra', function (): void {
    // Un usuario borrado se lleva por delante quién confirmó aquel pedido y
    // quién autorizó aquella anulación.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = equipo($this->slug, 'POST', '', [
        'name' => 'José', 'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->json('data.id');

    equipo($this->slug, 'DELETE', "/{$id}")->assertStatus(204);

    actingForTenant($this->tenant);

    $jose = User::find($id);

    expect($jose)->not->toBeNull()
        ->and($jose->is_active)->toBeFalse();
});

it('el que está de baja no entra', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = equipo($this->slug, 'POST', '', [
        'name' => 'José', 'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->json('data.id');

    equipo($this->slug, 'DELETE', "/{$id}")->assertStatus(204);

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'jose@ejemplo.com',
            'password' => 'clave-larga-123',
        ])->assertStatus(422);
});

it('siempre queda un dueño', function (): void {
    /*
     * Un negocio sin dueño activo es un negocio que nadie puede configurar, y
     * desde dentro no hay forma de arreglarlo: haría falta que alguien entrara
     * por la base de datos.
     */
    entrarComo($this->slug, 'maria@ejemplo.com');

    $otro = equipo($this->slug, 'POST', '', [
        'name' => 'José', 'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->json('data.id');

    // A María no se la puede bajar de dueña siendo la única.
    equipo($this->slug, 'PATCH', "/{$this->maria}", ['role_code' => 'manager'])
        ->assertStatus(422);

    // Con un segundo dueño, sí.
    equipo($this->slug, 'PATCH', "/{$otro}", ['role_code' => 'owner'])->assertOk();
    equipo($this->slug, 'PATCH', "/{$this->maria}", ['role_code' => 'manager'])->assertOk();
});

it('nadie se da de baja a sí mismo', function (): void {
    // Es el clic que deja a alguien fuera de su propio negocio un viernes por
    // la tarde.
    entrarComo($this->slug, 'maria@ejemplo.com');

    equipo($this->slug, 'DELETE', "/{$this->maria}")->assertStatus(422);
});

it('el mismo correo no se repite dentro del negocio', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    equipo($this->slug, 'POST', '', [
        'name' => 'Otra María', 'email' => 'maria@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('cambiar el PIN no cambia la contraseña, y quitarlo lo quita', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = equipo($this->slug, 'POST', '', [
        'name' => 'Ana', 'email' => 'ana@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'counter', 'pin' => '3456',
    ])->json('data.id');

    equipo($this->slug, 'PATCH', "/{$id}", ['pin' => '9876'])->assertOk();

    actingForTenant($this->tenant);
    expect(Hash::check('9876', (string) User::find($id)->pin_hash))->toBeTrue();

    // La contraseña sigue siendo la suya.
    entrarComo($this->slug, 'ana@ejemplo.com', 'clave-larga-123');

    // Y se vuelve a entrar como María: Ana no maneja usuarios, y seguir
    // llamando al equipo con su sesión probaría otra cosa.
    entrarComo($this->slug, 'maria@ejemplo.com');

    equipo($this->slug, 'PATCH', "/{$id}", ['pin' => ''])->assertOk();

    actingForTenant($this->tenant);
    expect(User::find($id)->pin_hash)->toBeNull();
});

it('un PIN que no son cuatro dígitos no se guarda', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = equipo($this->slug, 'POST', '', [
        'name' => 'Ana', 'email' => 'ana@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'counter',
    ])->json('data.id');

    equipo($this->slug, 'PATCH', "/{$id}", ['pin' => '12'])->assertStatus(422);
});

it('quien no maneja usuarios, no los ve', function (): void {
    actingForTenant($this->tenant);

    conRol($this->tenant, 'carlos@ejemplo.com', 'Carlos', 'kitchen');

    entrarComo($this->slug, 'carlos@ejemplo.com');

    equipo($this->slug)->assertForbidden();
});

it('el equipo de un negocio no incluye al de otro', function (): void {
    $sufijo = Str::lower(Str::random(6));
    $vecino = makeTenant("vecino-{$sufijo}", plan: 'negocio');

    actingForTenant($vecino);
    enableModule($vecino, 'core');
    giveRole($vecino, makeUser($vecino, 'ajeno@ejemplo.com', 'Ajeno'), 'owner');

    entrarComo($this->slug, 'maria@ejemplo.com');

    $correos = array_column(equipo($this->slug)->assertOk()->json('data'), 'email');

    expect($correos)->not->toContain('ajeno@ejemplo.com')
        ->toContain('maria@ejemplo.com');
});

it('la lista dice cuántos caben y quién tiene PIN', function (): void {
    // Sin PIN no se entra a la caja ni a la cocina, y eso hay que verlo de un
    // vistazo cuando alguien dice «no me deja».
    entrarComo($this->slug, 'maria@ejemplo.com');

    $respuesta = equipo($this->slug)->assertOk();

    expect($respuesta->json('meta.maxUsers'))->toBe(8)
        ->and($respuesta->json('meta.active'))->toBe(1);

    $maria = collect($respuesta->json('data'))->firstWhere('email', 'maria@ejemplo.com');

    expect($maria['isOwner'])->toBeTrue()
        ->and($maria['hasPin'])->toBeFalse();
});
