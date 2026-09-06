<?php

declare(strict_types=1);

/*
 * `GET /me` is the hub: the server combines plan × enabled modules × settings ×
 * permissions, and the frontend paints what it gets without deciding anything.
 *
 * These tests pin the four properties that make that work.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    enableModule($this->tenant, 'core');

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $this->carlos, 'kitchen');
});

it('carries the tenant\'s timezone, to date their data in their own time', function (): void {
    // The dashboard dates orders. Without this it would only have the browser's
    // timezone, and an owner abroad would see last night's order dated today.
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertOk()
        ->assertJsonPath('tenant.timezone', 'America/Caracas');
});

it('answers WITHOUT a session, with the tenant and zero permissions', function (): void {
    // The login screen needs the tenant's name and logo before anyone signs in.
    // A login saying "Kombo" instead of "El Sazón" looks like another product.
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertOk()
        ->assertJsonPath('tenant.slug', $this->slug)
        ->assertJsonPath('user', null)
        ->assertJsonPath('permissions', []);
});

it('the manager reaches the tenant\'s settings', function (): void {
    /*
     * The manager runs the shop when the owner is away, and until now could
     * touch neither the opening hours — without which the portal takes no
     * orders — nor the rate every bolívar price hangs off.
     *
     * `roleName` comes too: without it, "this cannot be done" and "you cannot
     * do this" look the same on screen.
     */
    enableModule($this->tenant, 'channels');

    $jose = makeUser($this->tenant, 'jose@ejemplo.com', 'José');
    giveRole($this->tenant, $jose, 'manager');

    loginAs($this->slug, 'jose@ejemplo.com');

    $me = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertOk();

    expect($me->json('permissions'))
        ->toContain('settings.manage')
        ->toContain('users.manage')
        ->toContain('channels.view')
        ->and($me->json('user.roleName'))->toBe('Encargado')
        ->and($me->json('user.isOwner'))->toBeFalse();
});

it('the owner gets the permissions of the modules enabled TODAY', function (): void {
    // Permissions are not stored one by one: `['*']` is expanded, so enabling a
    // new module makes it usable immediately.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $permissions = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('user.isOwner', true)
        ->json('permissions');

    expect($permissions)->toContain('settings.manage')
        ->and($permissions)->toContain('users.manage');
});

it('the kitchen receives only its own', function (): void {
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'carlos@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $permissions = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('user.isOwner', false)
        ->json('permissions');

    // No settings, no users. Only the ticket board — and since the kitchen
    // module does not exist in this version, not even that.
    expect($permissions)->not->toContain('settings.manage')
        ->and($permissions)->not->toContain('users.manage');
});

it('settings arrive with their default and their type, not as text', function (): void {
    // From the module manifest, not the database: `tenant_settings` only holds
    // what the tenant changed.
    //
    // The whole array is read rather than assertJsonPath: the key contains a dot
    // (`core.pin_length`) and there a dot is a path separator, not part of the
    // name.
    $settings = $this->getJson(urlFor($this->slug, '/api/v1/me'))->json('settings');

    expect($settings['core.pin_length'])->toBe(4)
        ->and($settings['core.pin_attempts'])->toBe(5);
});

it('a stored setting overrides the default, already cast', function (): void {
    DB::table('tenant_settings')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'key' => 'core.pin_length',
        'value' => '6',      // in the database it is ALWAYS text
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // And it reaches the client as an INTEGER, not '6': the manifest declares
    // the type and `Setting::cast()` applies it on read.
    $settings = $this->getJson(urlFor($this->slug, '/api/v1/me'))->json('settings');

    expect($settings['core.pin_length'])->toBe(6);
});

it('the plan ceilings arrive resolved, with null as unlimited', function (): void {
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('limits.maxUsers', 8)
        // `null` is UNLIMITED, never zero: zero means "none", a different answer and
        // far worse to debug.
        ->assertJsonPath('limits.maxProducts', null);
});

it('menu labels come from the manifest, not from a list in React', function (): void {
    $this->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('moduleNames.core', 'Configuración');
});

it('core modules do not switch off, not even by writing to the table', function (): void {
    DB::table('tenant_modules')
        ->where('tenant_id', $this->tenant)
        ->update(['enabled' => false]);

    // The core does not depend on the plan and cannot be switched off. Checked
    // by containment rather than exact equality, so adding a core module later
    // does not break this test for an unrelated reason.
    $modules = $this->getJson(urlFor($this->slug, '/api/v1/me'))->json('modules');

    expect($modules)->toContain('core')
        ->and($modules)->toContain('catalog');
});
