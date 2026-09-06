<?php

declare(strict_types=1);

/*
 * The tenant's team.
 *
 * What lets a shop grow from one person to five without anyone touching the
 * database. And where the plan ceiling means something: a limit that only shows
 * on an administration screen is not a limit.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Platform\Auth\RoleProvisioner;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders'] as $module) {
        enableModule($this->tenant, $module);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    // The base roles have to exist to be handed out, and WITH their permissions:
    // part of what is tested here is what a manager can do, and a role with no
    // permission rows can do nothing. The same object the real seeding uses,
    // rather than writing them by hand — which is how the test world ends up
    // different from production.
    app(RoleProvisioner::class)->reconcile($this->tenant);
});

/** Somebody on the team with a role that already exists in the tenant. */
function withRole(string $tenantId, string $email, string $name, string $code): string
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

function team(string $slug, string $method = 'GET', string $path = '', array $body = []): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->json($method, urlFor($slug, "/api/v1/team{$path}"), $body);
}

it('the owner adds somebody to the team, and that person signs in', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    team($this->slug, 'POST', '', [
        'name' => 'José',
        'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123',
        'role_code' => 'manager',
        'pin' => '2345',
    ])->assertCreated();

    // And they really sign in: if the password were stored wrong — hashed twice,
    // say — this would fail, and the failure would not say why.
    loginAs($this->slug, 'jose@ejemplo.com', 'clave-larga-123');

    actingForTenant($this->tenant);

    $jose = User::where('email', 'jose@ejemplo.com')->first();

    expect($jose->roles->first()?->code)->toBe('manager')
        // And their PIN works at the till: hashed twice it would open nothing.
        ->and(Hash::check('2345', (string) $jose->pin_hash))->toBeTrue();
});

it('the plan ceiling applies ON CREATE, not in a report', function (): void {
    // The starter plan goes up to 2 people.
    $suffix = Str::lower(Str::random(6));
    $slug = "pequeno-{$suffix}";
    $tenantId = makeTenant($slug, plan: 'starter');

    actingForTenant($tenantId);
    enableModule($tenantId, 'core');

    $owner = makeUser($tenantId, 'duena@ejemplo.com', 'Dueña');
    giveRole($tenantId, $owner, 'owner');
    giveRole($tenantId, makeUser($tenantId, 'otro@ejemplo.com', 'Otro'), 'kitchen');

    loginAs($slug, 'duena@ejemplo.com');

    $response = team($slug, 'POST', '', [
        'name' => 'Tercero',
        'email' => 'tercero@ejemplo.com',
        'password' => 'clave-larga-123',
        'role_code' => 'kitchen',
    ])->assertStatus(422);

    // And the message says what to do, not just that it cannot be done.
    expect($response->json('message'))->toContain('subir de plan');
});

it('somebody deactivated does not occupy a seat that is paid for', function (): void {
    $suffix = Str::lower(Str::random(6));
    $slug = "recicla-{$suffix}";
    $tenantId = makeTenant($slug, plan: 'starter');

    actingForTenant($tenantId);
    enableModule($tenantId, 'core');

    $owner = makeUser($tenantId, 'duena@ejemplo.com', 'Dueña');
    giveRole($tenantId, $owner, 'owner');

    $old = makeUser($tenantId, 'viejo@ejemplo.com', 'Viejo');
    giveRole($tenantId, $old, 'kitchen');

    loginAs($slug, 'duena@ejemplo.com');

    // With two active, the starter plan is full.
    team($slug, 'POST', '', [
        'name' => 'Nuevo', 'email' => 'nuevo@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'kitchen',
    ])->assertStatus(422);

    team($slug, 'DELETE', "/{$old}")->assertStatus(204);

    // Now yes: somebody who left three months ago cannot keep costing money.
    team($slug, 'POST', '', [
        'name' => 'Nuevo', 'email' => 'nuevo@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'kitchen',
    ])->assertCreated();
});

it('deactivating DEACTIVATES, it does not delete', function (): void {
    // A deleted user takes with them who confirmed that order and who authorised
    // that void.
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = team($this->slug, 'POST', '', [
        'name' => 'José', 'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->json('data.id');

    team($this->slug, 'DELETE', "/{$id}")->assertStatus(204);

    actingForTenant($this->tenant);

    $jose = User::find($id);

    expect($jose)->not->toBeNull()
        ->and($jose->is_active)->toBeFalse();
});

it('a deactivated user does not get in', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = team($this->slug, 'POST', '', [
        'name' => 'José', 'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->json('data.id');

    team($this->slug, 'DELETE', "/{$id}")->assertStatus(204);

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/auth/login'), [
            'email' => 'jose@ejemplo.com',
            'password' => 'clave-larga-123',
        ])->assertStatus(422);
});

it('there is always one owner left', function (): void {
    /*
     * A tenant with no active owner cannot be configured, and there is no way
     * to fix it from the inside — somebody would have to go in through the
     * database.
     */
    loginAs($this->slug, 'maria@ejemplo.com');

    $other = team($this->slug, 'POST', '', [
        'name' => 'José', 'email' => 'jose@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->json('data.id');

    // María cannot be demoted while she is the only owner.
    team($this->slug, 'PATCH', "/{$this->maria}", ['role_code' => 'manager'])
        ->assertStatus(422);

    // With a second owner, she can.
    team($this->slug, 'PATCH', "/{$other}", ['role_code' => 'owner'])->assertOk();
    team($this->slug, 'PATCH', "/{$this->maria}", ['role_code' => 'manager'])->assertOk();
});

it('nobody deactivates themselves', function (): void {
    // The click that leaves somebody outside their own business on a Friday
    // afternoon.
    loginAs($this->slug, 'maria@ejemplo.com');

    team($this->slug, 'DELETE', "/{$this->maria}")->assertStatus(422);
});

it('the same email does not repeat within a tenant', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    team($this->slug, 'POST', '', [
        'name' => 'Otra María', 'email' => 'maria@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'manager',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('changing the PIN does not change the password, and clearing it removes it', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = team($this->slug, 'POST', '', [
        'name' => 'Ana', 'email' => 'ana@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'counter', 'pin' => '3456',
    ])->json('data.id');

    team($this->slug, 'PATCH', "/{$id}", ['pin' => '9876'])->assertOk();

    actingForTenant($this->tenant);
    expect(Hash::check('9876', (string) User::find($id)->pin_hash))->toBeTrue();

    // The password is still hers.
    loginAs($this->slug, 'ana@ejemplo.com', 'clave-larga-123');

    // And we sign back in as María: Ana does not manage users, and calling the
    // team endpoint with her session would test something else.
    loginAs($this->slug, 'maria@ejemplo.com');

    team($this->slug, 'PATCH', "/{$id}", ['pin' => ''])->assertOk();

    actingForTenant($this->tenant);
    expect(User::find($id)->pin_hash)->toBeNull();
});

it('a PIN that is not four digits is not stored', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = team($this->slug, 'POST', '', [
        'name' => 'Ana', 'email' => 'ana@ejemplo.com',
        'password' => 'clave-larga-123', 'role_code' => 'counter',
    ])->json('data.id');

    team($this->slug, 'PATCH', "/{$id}", ['pin' => '12'])->assertStatus(422);
});

/*
 * How far the manager reaches.
 *
 * They used to reach none of this: no `users.manage`, because whoever creates
 * users can create themselves an owner account. But removing the permission
 * also blocked the legitimate case — signing up the new cook on a Saturday — so
 * now they have it and the hole is closed where it is really decided.
 */

it('the manager adds people to the team', function (): void {
    actingForTenant($this->tenant);
    withRole($this->tenant, 'jose@ejemplo.com', 'José', 'manager');

    loginAs($this->slug, 'jose@ejemplo.com');

    team($this->slug, 'POST', '', [
        'name' => 'Carlos',
        'email' => 'carlos@ejemplo.com',
        'password' => 'clave-larga-123',
        'role_code' => 'kitchen',
        'pin' => '4567',
    ])->assertCreated();
});

it('the manager cannot appoint another owner', function (): void {
    actingForTenant($this->tenant);
    withRole($this->tenant, 'jose@ejemplo.com', 'José', 'manager');

    loginAs($this->slug, 'jose@ejemplo.com');

    team($this->slug, 'POST', '', [
        'name' => 'Intruso',
        'email' => 'intruso@ejemplo.com',
        'password' => 'clave-larga-123',
        'role_code' => 'owner',
    ])->assertStatus(422);

    actingForTenant($this->tenant);
    expect(User::where('email', 'intruso@ejemplo.com')->exists())->toBeFalse();
});

it('the manager cannot promote anybody to owner', function (): void {
    actingForTenant($this->tenant);
    withRole($this->tenant, 'jose@ejemplo.com', 'José', 'manager');
    $ana = withRole($this->tenant, 'ana@ejemplo.com', 'Ana', 'counter');

    loginAs($this->slug, 'jose@ejemplo.com');

    team($this->slug, 'PATCH', "/{$ana}", ['role_code' => 'owner'])->assertStatus(422);

    actingForTenant($this->tenant);
    expect(User::find($ana)->isOwner())->toBeFalse();
});

/*
 * This is the one that actually closes the door.
 *
 * Without it the rule above is decorative: `update()` accepts `password`, so a
 * manager only had to change the owner's password and sign in as them. No
 * promotion required.
 */
it('the manager does not change the owner\'s password', function (): void {
    actingForTenant($this->tenant);
    withRole($this->tenant, 'jose@ejemplo.com', 'José', 'manager');

    loginAs($this->slug, 'jose@ejemplo.com');

    team($this->slug, 'PATCH', "/{$this->maria}", ['password' => 'me-la-quedo-yo'])
        ->assertForbidden();

    // And María's password is still hers.
    loginAs($this->slug, 'maria@ejemplo.com');
});

it('the manager does not deactivate the owner', function (): void {
    actingForTenant($this->tenant);
    withRole($this->tenant, 'jose@ejemplo.com', 'José', 'manager');

    loginAs($this->slug, 'jose@ejemplo.com');

    team($this->slug, 'DELETE', "/{$this->maria}")->assertForbidden();

    actingForTenant($this->tenant);
    expect(User::find($this->maria)->is_active)->toBeTrue();
});

it('the manager is not offered the owner role, and the owner is', function (): void {
    // Showing an option the server will reject is worse than hiding it: you find
    // out after filling in the whole form.
    actingForTenant($this->tenant);
    withRole($this->tenant, 'jose@ejemplo.com', 'José', 'manager');

    loginAs($this->slug, 'jose@ejemplo.com');
    $forJose = array_column(team($this->slug)->assertOk()->json('meta.roles'), 'code');

    loginAs($this->slug, 'maria@ejemplo.com');
    $forMaria = array_column(team($this->slug)->assertOk()->json('meta.roles'), 'code');

    expect($forJose)->not->toContain('owner')->toContain('kitchen')
        ->and($forMaria)->toContain('owner');
});

it('whoever does not manage users does not see them', function (): void {
    actingForTenant($this->tenant);

    withRole($this->tenant, 'carlos@ejemplo.com', 'Carlos', 'kitchen');

    loginAs($this->slug, 'carlos@ejemplo.com');

    team($this->slug)->assertForbidden();
});

it('one tenant\'s team does not include another\'s', function (): void {
    $suffix = Str::lower(Str::random(6));
    $neighbour = makeTenant("vecino-{$suffix}", plan: 'business');

    actingForTenant($neighbour);
    enableModule($neighbour, 'core');
    giveRole($neighbour, makeUser($neighbour, 'ajeno@ejemplo.com', 'Ajeno'), 'owner');

    loginAs($this->slug, 'maria@ejemplo.com');

    $emails = array_column(team($this->slug)->assertOk()->json('data'), 'email');

    expect($emails)->not->toContain('ajeno@ejemplo.com')
        ->toContain('maria@ejemplo.com');
});

it('the list says how many fit and who has a PIN', function (): void {
    // Without a PIN there is no till and no kitchen, and that has to be visible
    // at a glance when somebody says "it won't let me in".
    loginAs($this->slug, 'maria@ejemplo.com');

    $response = team($this->slug)->assertOk();

    expect($response->json('meta.maxUsers'))->toBe(8)
        ->and($response->json('meta.active'))->toBe(1);

    $maria = collect($response->json('data'))->firstWhere('email', 'maria@ejemplo.com');

    expect($maria['isOwner'])->toBeTrue()
        ->and($maria['hasPin'])->toBeFalse();
});
