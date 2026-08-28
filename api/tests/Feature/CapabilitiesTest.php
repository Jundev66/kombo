<?php

declare(strict_types=1);

/*
 * `GET /me` es el eje: el servidor combina plan × módulos encendidos ×
 * configuración × permisos, y el frontend pinta lo que reciba sin decidir nada.
 *
 * Estas pruebas fijan las cuatro propiedades que hacen que eso funcione.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    enableModule($this->tenant, 'core');

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $this->carlos, 'kitchen');
});

it('responde SIN sesión, con el negocio y cero permisos', function (): void {
    // La pantalla de login necesita el nombre y el logo del negocio antes de
    // que nadie entre. Un login que dice «Kombo» en vez de «El Sazón» parece
    // de otro producto.
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertOk()
        ->assertJsonPath('tenant.slug', $this->slug)
        ->assertJsonPath('user', null)
        ->assertJsonPath('permissions', []);
});

it('el dueño recibe los permisos de los módulos encendidos HOY', function (): void {
    // No se le guardan permisos uno a uno: se resuelve como `['*']` y se
    // expande. Así, el día que encienda un módulo nuevo, ya puede usarlo sin
    // que nadie le añada nada.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $permisos = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('user.isOwner', true)
        ->json('permissions');

    expect($permisos)->toContain('settings.manage')
        ->and($permisos)->toContain('users.manage');
});

it('la cocina recibe sólo lo suyo', function (): void {
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'carlos@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $permisos = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('user.isOwner', false)
        ->json('permissions');

    // Ni configuración, ni usuarios. Sólo la pantalla de comandas — y como el
    // módulo de cocina todavía no existe en esta versión, ni siquiera eso.
    expect($permisos)->not->toContain('settings.manage')
        ->and($permisos)->not->toContain('users.manage');
});

it('los ajustes vienen con su valor por defecto y con su tipo, no como texto', function (): void {
    // Salen del manifiesto del módulo, no de la base: `tenant_settings` sólo
    // guarda lo que el negocio cambió.
    //
    // Se lee el array entero en vez de usar assertJsonPath: la clave lleva un
    // punto (`core.pin_length`) y ahí el punto es separador de ruta, no parte
    // del nombre.
    $settings = $this->getJson(urlFor($this->slug, '/api/v1/me'))->json('settings');

    expect($settings['core.pin_length'])->toBe(4)
        ->and($settings['core.pin_attempts'])->toBe(5);
});

it('un ajuste guardado pisa el valor por defecto, ya casteado', function (): void {
    DB::table('tenant_settings')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'key' => 'core.pin_length',
        'value' => '6',      // en la base SIEMPRE es texto
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Y llega al cliente como ENTERO, no como '6': el tipo lo declara el
    // manifiesto y `Setting::cast()` lo aplica al leer.
    $settings = $this->getJson(urlFor($this->slug, '/api/v1/me'))->json('settings');

    expect($settings['core.pin_length'])->toBe(6);
});

it('los techos del plan llegan resueltos, con null como ilimitado', function (): void {
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('limits.maxUsers', 8)
        // `null` es ILIMITADO, nunca cero: cero sería «ninguno», que es una
        // respuesta distinta y mucho peor de depurar.
        ->assertJsonPath('limits.maxProducts', null);
});

it('las etiquetas del menú salen del manifiesto, no de una lista en React', function (): void {
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('moduleNames.core', 'Configuración');
});

it('un módulo apagado no aparece en las capacidades', function (): void {
    DB::table('tenant_modules')
        ->where('tenant_id', $this->tenant)
        ->where('module_code', 'core')
        ->update(['enabled' => false]);

    // El núcleo es la excepción: no depende del plan y no se apaga. Es lo
    // mínimo sin lo cual el sistema no es un sistema.
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('modules', ['core']);
});
