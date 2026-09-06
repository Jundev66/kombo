<?php

declare(strict_types=1);

/*
 * The menu over HTTP: permissions, plan ceilings, and the separation between
 * editing a product and changing its price.
 */

use App\Models\Catalog\ProductModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    enableModule($this->tenant, 'core');
    enableModule($this->tenant, 'catalog');

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $this->carlos, 'kitchen');
});

it('the owner adds a product to the menu', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $response = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => '  Reina   Pepiada  ',
            'price_cents' => 300,
            'prep_minutes' => 8,
        ]);

    $response->assertCreated()
        // The name arrives normalised: the domain cleaned it before saving.
        ->assertJsonPath('data.name', 'Reina Pepiada')
        ->assertJsonPath('data.priceCents', 300)
        ->assertJsonPath('data.prepMinutes', 8)
        // Stamped from day one, so "how long since I reviewed this price?" has an
        // answer.
        ->assertJsonPath('data.isActive', true);

    expect($response->json('data.priceUpdatedAt'))->not->toBeNull();
});

it('rejects a negative price, and says so on the right field', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada',
            'price_cents' => -100,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('price_cents');
});

it('the kitchen cannot touch the menu', function (): void {
    // Carlos only has the ticket board. That he cannot edit prices is not
    // distrust: it is not his job, and a stray tap with full hands is expensive.
    loginAs($this->slug, 'carlos@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Lo que sea',
            'price_cents' => 100,
        ])
        ->assertForbidden();
});

it('editing a product CANNOT change its price', function (): void {
    // If `price_cents` slipped through here, the separate price permission would
    // be decorative.
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada', 'price_cents' => 300,
        ])->json('data.id');

    $this->withHeaders(browsingAs($this->slug))
        ->patchJson(urlFor($this->slug, "/api/v1/catalog/products/{$id}"), [
            'name' => 'Reina Pepiada Especial',
            'price_cents' => 1,          // ← ignored, not applied
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Reina Pepiada Especial')
        ->assertJsonPath('data.priceCents', 300);
});

it('changing the price leaves a trail with the before and the after', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada', 'price_cents' => 300,
        ])->json('data.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/catalog/products/{$id}/price"), ['price_cents' => 350])
        ->assertOk()
        ->assertJsonPath('data.priceCents', 350);

    actingForTenant($this->tenant);

    $entry = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->where('action', 'catalog.price_changed')
        ->first();

    expect(json_decode((string) $entry?->before, true))->toBe(['price_cents' => 300])
        ->and(json_decode((string) $entry?->after, true))->toBe(['price_cents' => 350]);
});

it('setting the same price dirties neither the audit log nor the date', function (): void {
    // An audit log full of "changed from 3.00 to 3.00" is one nobody reads, and
    // a date that moves for no reason stops telling you what needs reviewing.
    loginAs($this->slug, 'maria@ejemplo.com');

    $created = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada', 'price_cents' => 300,
        ])->json('data');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/catalog/products/{$created['id']}/price"), ['price_cents' => 300])
        ->assertOk()
        ->assertJsonPath('data.priceUpdatedAt', $created['priceUpdatedAt']);

    actingForTenant($this->tenant);

    expect(DB::table('audit_log')->where('action', 'catalog.price_changed')->count())->toBe(0);
});

it('the plan ceiling stops it, and says how many fit', function (): void {
    // A tiny plan, to avoid creating sixty products.
    DB::table('plans')->insert([
        'code' => 'diminuto', 'name' => 'Diminuto', 'currency' => 'USD',
        'max_products' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('plan_modules')->insert([
        ['plan_code' => 'diminuto', 'module_code' => 'catalog'],
    ]);
    DB::table('tenants')->where('id', $this->tenant)->update(['plan_code' => 'diminuto']);

    loginAs($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), ['name' => 'Primero', 'price_cents' => 100])
        ->assertCreated();

    $response = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), ['name' => 'Segundo', 'price_cents' => 100])
        ->assertStatus(422);

    // The message says what to do, not just that it cannot be done.
    expect($response->json('message'))->toContain('1 productos');
});

it('a modifier group arrives with its rule already explained', function (): void {
    // The portal, the till and the bot all show the same sentence. Three
    // validations would be three chances for one to go stale.
    loginAs($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/modifier-groups'), [
            'name' => 'Término de la carne',
            'min_choices' => 1,
            'max_choices' => 1,
            'modifiers' => [
                ['name' => 'Tres cuartos', 'price_delta_cents' => 0],
                ['name' => 'Bien cocida', 'price_delta_cents' => 0],
            ],
        ])->assertCreated();

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/catalog/modifier-groups'))
        ->assertOk()
        ->assertJsonPath('data.0.rule', 'Elige una opción.')
        ->assertJsonPath('data.0.modifiers.1.name', 'Bien cocida');
});

it('a modifier CAN take money off', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/modifier-groups'), [
            'name' => 'Extras',
            'modifiers' => [['name' => 'Sin queso', 'price_delta_cents' => -50]],
        ])->assertCreated();

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/catalog/modifier-groups'))
        ->assertJsonPath('data.0.modifiers.0.priceDeltaCents', -50);
});

it('the menu is core: nobody can switch it off, not even by writing to the table', function (): void {
    // A food business with no menu is nothing, and everything else hangs off it.
    // That is why `isCore()` switches it on whatever `tenant_modules` says — the
    // row is written by hand here precisely to show it is not enough.
    DB::table('tenant_modules')
        ->where('tenant_id', $this->tenant)
        ->where('module_code', 'catalog')
        ->update(['enabled' => false]);

    loginAs($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/catalog/products'))
        ->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('modules', fn (array $modules): bool => in_array('catalog', $modules, true));
});

it('a module this tenant does not have answers 404, not 403', function (): void {
    // 404 and not 403 on purpose: a module a tenant does not have is information
    // about their CONTRACT, not their permissions. A 403 would say "this exists
    // but you may not", which invites insistence and leaks the feature list.
    loginAs($this->slug, 'maria@ejemplo.com');

    // `counter` arrives in phase 5. Today it exists for nobody, which is exactly
    // the case being checked.
    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/counter/anything'))
        ->assertNotFound();
});

it('the photo uploads, replaces the previous one, and can be removed', function (): void {
    /*
     * On the portal the photo is what sells. Pasting an address by hand meant,
     * in practice, that almost no menu had photos.
     */
    Storage::fake('public');

    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    $product = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $first = test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$product->id}/photo"), [
            'photo' => UploadedFile::fake()->create('arepa.jpg', 200, 'image/jpeg'),
        ])->assertOk()->json('data.photoUrl');

    // A relative path: served by the same origin the page was opened from, which
    // is the tenant's subdomain rather than the root domain.
    expect($first)->toStartWith("/storage/products/{$this->tenant}/");

    Storage::disk('public')->assertExists(str_replace('/storage/', '', $first));

    $second = test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$product->id}/photo"), [
            'photo' => UploadedFile::fake()->create('otra.jpg', 200, 'image/jpeg'),
        ])->assertOk()->json('data.photoUrl');

    // The previous one is deleted, or every change leaves an orphan and a small
    // VPS's disk fills with six identical arepas.
    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing(str_replace('/storage/', '', $first));

    test()->withHeaders(browsingAs($this->slug))
        ->deleteJson(urlFor($this->slug, "/api/v1/catalog/products/{$product->id}/photo"))
        ->assertStatus(204);

    actingForTenant($this->tenant);

    expect(ProductModel::find($product->id)->photo_url)->toBeNull();
    Storage::disk('public')->assertMissing(str_replace('/storage/', '', $second));
});

it('what is not a photo is not uploaded', function (): void {
    Storage::fake('public');

    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    $product = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$product->id}/photo"), [
            'photo' => UploadedFile::fake()->create('cualquier.jpg', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
});

it('whoever does not manage the menu changes nothing\'s photo', function (): void {
    Storage::fake('public');

    actingForTenant($this->tenant);

    $product = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $carlos = makeUser($this->tenant, 'carlos-foto@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    loginAs($this->slug, 'carlos-foto@ejemplo.com');

    test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$product->id}/photo"), [
            'photo' => UploadedFile::fake()->create('arepa.jpg', 100, 'image/jpeg'),
        ])->assertForbidden();
});
