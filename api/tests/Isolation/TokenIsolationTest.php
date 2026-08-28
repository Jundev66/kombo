<?php

declare(strict_types=1);

/*
 * Un token de un negocio NO sirve en otro.
 *
 * Con la tabla de tokens que trae Sanctum, esto sería falso: no tiene
 * `tenant_id` y nadie lo comprueba, así que el token de la tablet de una
 * arepera abriría la caja de la pizzería de al lado. Por eso hay una tabla
 * propia, de negocio y con RLS.
 *
 * El detalle bonito es que no hace falta escribir la comprobación: el token
 * ajeno no es que esté prohibido, es que **la consulta no lo encuentra**.
 */

use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = "elsazon-{$sufijo}";
    $this->pizzeria = "laesquina-{$sufijo}";

    $areperaId = makeTenant($this->arepera);
    actingForTenant($areperaId);
    enableModule($areperaId, 'core');
    $maria = makeUser($areperaId, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($areperaId, $maria, 'owner');

    $pizzeriaId = makeTenant($this->pizzeria);
    actingForTenant($pizzeriaId);
    enableModule($pizzeriaId, 'core');
    $pedro = makeUser($pizzeriaId, 'pedro@ejemplo.com', 'Pedro', pin: '5678');
    giveRole($pizzeriaId, $pedro, 'owner');

    // Un token de dispositivo de la arepera.
    $this->tokenDeLaArepera = $this->postJson(urlFor($this->arepera, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ])->json('token');
});

it('el token de un negocio funciona en su propio negocio', function (): void {
    $this->withHeader('Authorization', "Bearer {$this->tokenDeLaArepera}")
        ->getJson(urlFor($this->arepera, '/api/v1/auth/staff'))
        ->assertOk()
        ->assertJsonPath('staff.0.name', 'María');
});

it('el token de un negocio NO sirve en otro negocio', function (): void {
    // Mismo token, otro subdominio. Para la pizzería ese token no existe.
    $this->withHeader('Authorization', "Bearer {$this->tokenDeLaArepera}")
        ->getJson(urlFor($this->pizzeria, '/api/v1/auth/staff'))
        ->assertUnauthorized();
});

it('sin token no se ve ni la lista de nombres', function (): void {
    // La lista de quién trabaja aquí no es pública: dice cuánta gente hay, cómo
    // se llaman y qué rol tienen.
    $this->getJson(urlFor($this->arepera, '/api/v1/auth/staff'))
        ->assertUnauthorized();
});
