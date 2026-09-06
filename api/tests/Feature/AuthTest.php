<?php

declare(strict_types=1);

/*
 * The three ways in, and why there are three.
 *
 * The dashboard signs in with email and password, where there is a keyboard and
 * time. The till and the kitchen use a device token plus a PIN, because they
 * are shared machines and nobody types an email with their hands full.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug);

    // Context goes BEFORE writing any tenant row: `WITH CHECK` rejects an
    // insert with no tenant in context, and that is correct.
    actingForTenant($this->tenant);

    enableModule($this->tenant, 'core');
    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');
});

it('the owner signs in with their email and password', function (): void {
    $response = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ]);

    $response->assertOk()->assertJson(['ok' => true]);

    // Asserting the CAUSE as well as the symptom: a 200 does not prove there is
    // a session. `/me` returning the user does.
    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertOk()
        ->assertJsonPath('user.name', 'María')
        ->assertJsonPath('user.isOwner', true);
});

it('the same email in two tenants signs into the one for the subdomain', function (): void {
    // Why the tenant middleware runs BEFORE authentication, and why the email is
    // unique per tenant rather than globally.
    $otherSlug = 'laesquina-'.Str::lower(Str::random(6));
    $other = makeTenant($otherSlug);

    actingForTenant($other);
    enableModule($other, 'core');
    $pedro = makeUser($other, 'maria@ejemplo.com', 'Pedro de La Esquina', pin: '9999');
    giveRole($other, $pedro, 'owner');

    $this->withHeaders(browsingAs($otherSlug))
        ->postJson(urlFor($otherSlug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $this->withHeaders(browsingAs($otherSlug))
        ->getJson(urlFor($otherSlug, '/api/v1/me'))
        ->assertJsonPath('user.name', 'Pedro de La Esquina');
});

it('the wrong password does not get in', function (): void {
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'la-que-no-es',
        ])->assertStatus(422);
});

it('a deactivated user does not get in, even knowing the password', function (): void {
    DB::table('users')->where('id', $this->maria)->update(['is_active' => false]);

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertStatus(422);
});

it('the screen registers once and gets a token that does NOT operate', function (): void {
    $response = $this->postJson(urlFor($this->slug, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ]);

    $response->assertOk()->assertJsonStructure(['token', 'device']);

    // `ResolveTenant::terminate()` cleared the tenant from the connection, which
    // is exactly right before returning it to the pool. To look at the database
    // it has to be pinned again.
    actingForTenant($this->tenant);

    // Its only ability is `device`. That tablet gets lent and lost: if the token
    // could sell or void, losing it would be losing the business.
    $abilities = DB::table('personal_access_tokens')
        ->where('tenant_id', $this->tenant)
        ->where('name', 'Cocina')
        ->value('abilities');

    expect(json_decode((string) $abilities, true))->toBe(['device']);
});

it('the device token shows the list of names, but not the emails', function (): void {
    $token = $this->postJson(urlFor($this->slug, '/api/v1/auth/device'), [
        'email' => 'maria@ejemplo.com',
        'password' => 'demo1234',
        'device' => 'Cocina',
    ])->json('token');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(urlFor($this->slug, '/api/v1/auth/staff'));

    $response->assertOk()->assertJsonPath('staff.0.name', 'María');

    // Never the email or the PIN hash: the list is painted on a screen anyone
    // walking past the counter can see.
    expect($response->json('staff.0'))->not->toHaveKey('email')
        ->and($response->json('staff.0'))->not->toHaveKey('pin_hash');
});

it('the right PIN opens the shift in the person\'s name', function (): void {
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

    // The tenant has to be pinned again to look at the database, after
    // `ResolveTenant::terminate()` cleared it from the connection.
    actingForTenant($this->tenant);

    // And the audit log names María, not the device token.
    $entry = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->where('action', 'auth.pin_login')
        ->first();

    expect($entry?->user_name)->toBe('María');
});

it('the wrong PIN opens nothing', function (): void {
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

it('records signing in and signing out in the audit log', function (): void {
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'maria@ejemplo.com',
            'password' => 'demo1234',
        ])->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/logout'))
        ->assertOk();

    // The tenant has to be pinned again to look at the database, after
    // `ResolveTenant::terminate()` cleared it from the connection.
    actingForTenant($this->tenant);

    $actions = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->pluck('action')
        ->all();

    expect($actions)->toContain('auth.login')
        ->and($actions)->toContain('auth.logout');
});
