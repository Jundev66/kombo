<?php

declare(strict_types=1);

/*
 * One tenant's token does NOT work in another.
 *
 * With Sanctum's own tokens table this would be false: it has no `tenant_id`
 * and nobody checks it, so a tablet token from one shop would open the till
 * next door. Hence our own table, tenant-scoped and under RLS.
 *
 * The nice part is that no check has to be written: the foreign token is not
 * forbidden, the query simply does not find it.
 */

use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->arepera = "elsazon-{$suffix}";
    $this->pizzeria = "laesquina-{$suffix}";

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

    // A device token from the arepera.
    $this->areperaToken = $this->postJson(urlFor($this->arepera, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ])->json('token');
});

it('a tenant\'s token works in its own tenant', function (): void {
    $this->withHeader('Authorization', "Bearer {$this->areperaToken}")
        ->getJson(urlFor($this->arepera, '/api/v1/auth/staff'))
        ->assertOk()
        ->assertJsonPath('staff.0.name', 'María');
});

it('a tenant\'s token does NOT work in another tenant', function (): void {
    // Same token, another subdomain. For the pizzeria it does not exist.
    $this->withHeader('Authorization', "Bearer {$this->areperaToken}")
        ->getJson(urlFor($this->pizzeria, '/api/v1/auth/staff'))
        ->assertUnauthorized();
});

it('without a token not even the list of names is visible', function (): void {
    // Who works here is not public: it says how many people there are, their
    // names and their roles.
    $this->getJson(urlFor($this->arepera, '/api/v1/auth/staff'))
        ->assertUnauthorized();
});
