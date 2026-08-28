<?php

declare(strict_types=1);

/*
 * Las tres puertas de entrada, y por qué son tres.
 *
 * El panel entra con correo y contraseña, donde hay un teclado y tiempo. La
 * caja y la cocina entran con el token del dispositivo más un PIN, porque son
 * máquinas compartidas del local y nadie escribe un correo con las manos
 * ocupadas y un cliente esperando.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug);

    // El contexto va ANTES de escribir cualquier fila de negocio: `WITH CHECK`
    // rechaza un insert sin negocio en contexto, y eso es lo correcto.
    actingForTenant($this->tenant);

    enableModule($this->tenant, 'core');
    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');
});

it('el dueño entra con su correo y su contraseña', function (): void {
    $response = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ]);

    $response->assertOk()->assertJson(['ok' => true]);

    // Afirmar la CAUSA además del síntoma: que responda 200 no prueba que haya
    // sesión. Que /me devuelva al usuario, sí.
    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertOk()
        ->assertJsonPath('user.name', 'María')
        ->assertJsonPath('user.isOwner', true);
});

it('el mismo correo en dos negocios entra al que corresponde al subdominio', function (): void {
    // Éste es el motivo de que el middleware de negocio corra ANTES de la
    // autenticación, y de que el correo sea único por negocio y no global.
    $otroSlug = 'laesquina-'.Str::lower(Str::random(6));
    $otro = makeTenant($otroSlug);

    actingForTenant($otro);
    enableModule($otro, 'core');
    $pedro = makeUser($otro, 'maria@ejemplo.com', 'Pedro de La Esquina', pin: '9999');
    giveRole($otro, $pedro, 'owner');

    $this->withHeaders(browsingAs($otroSlug))
        ->postJson(urlFor($otroSlug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $this->withHeaders(browsingAs($otroSlug))
        ->getJson(urlFor($otroSlug, '/api/v1/me'))
        ->assertJsonPath('user.name', 'Pedro de La Esquina');
});

it('una contraseña que no es, no entra', function (): void {
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'la-que-no-es',
        ])->assertStatus(422);
});

it('un usuario desactivado no entra, aunque sepa la contraseña', function (): void {
    DB::table('users')->where('id', $this->maria)->update(['is_active' => false]);

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertStatus(422);
});

it('la pantalla se da de alta una vez y recibe un token que NO opera', function (): void {
    $response = $this->postJson(urlFor($this->slug, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ]);

    $response->assertOk()->assertJsonStructure(['token', 'device']);

    // Tras la petición HTTP, `ResolveTenant::terminate()` limpió el negocio de
    // la conexión —que es justo lo que debe hacer antes de devolverla al pool—.
    // Para poder mirar la base hay que volver a fijarlo.
    actingForTenant($this->tenant);

    // Su única habilidad es `device`. Esa tablet se presta y se pierde: si el
    // token sirviera para vender o anular, perderla sería perder el negocio.
    $abilities = DB::table('personal_access_tokens')
        ->where('tenant_id', $this->tenant)
        ->where('name', 'Cocina')
        ->value('abilities');

    expect(json_decode((string) $abilities, true))->toBe(['device']);
});

it('con el token del equipo se ve la lista de nombres, pero no los correos', function (): void {
    $token = $this->postJson(urlFor($this->slug, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ])->json('token');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(urlFor($this->slug, '/api/v1/auth/staff'));

    $response->assertOk()->assertJsonPath('staff.0.name', 'María');

    // Nunca el correo ni el hash del PIN: la lista se pinta en una pantalla
    // que ve cualquiera que pase por el mostrador.
    expect($response->json('staff.0'))->not->toHaveKey('email')
        ->and($response->json('staff.0'))->not->toHaveKey('pin_hash');
});

it('el PIN correcto abre el turno a nombre de la persona', function (): void {
    $token = $this->postJson(urlFor($this->slug, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ])->json('token');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(urlFor($this->slug, '/api/v1/auth/pin'), [
            'user_id' => $this->maria,
            'pin' => '1234',
            'device' => 'Cocina',
        ]);

    $response->assertOk()->assertJsonPath('user.name', 'María');

    // Tras la petición HTTP, `ResolveTenant::terminate()` limpió el negocio de
    // la conexión —que es justo lo que debe hacer antes de devolverla al pool—.
    // Para poder mirar la base hay que volver a fijarlo.
    actingForTenant($this->tenant);

    // Y queda en la bitácora a nombre de María, no del token del dispositivo.
    $entrada = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->where('action', 'auth.pin_login')
        ->first();

    expect($entrada?->user_name)->toBe('María');
});

it('un PIN que no es, no abre nada', function (): void {
    $token = $this->postJson(urlFor($this->slug, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ])->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(urlFor($this->slug, '/api/v1/auth/pin'), [
            'user_id' => $this->maria,
            'pin' => '0000',
            'device' => 'Cocina',
        ])->assertStatus(422);
});

it('registra la entrada y la salida en la bitácora', function (): void {
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/logout'))
        ->assertOk();

    // Tras la petición HTTP, `ResolveTenant::terminate()` limpió el negocio de
    // la conexión —que es justo lo que debe hacer antes de devolverla al pool—.
    // Para poder mirar la base hay que volver a fijarlo.
    actingForTenant($this->tenant);

    $acciones = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->pluck('action')
        ->all();

    expect($acciones)->toContain('auth.login')
        ->and($acciones)->toContain('auth.logout');
});
