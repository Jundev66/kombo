<?php

declare(strict_types=1);

/*
 * La carta por HTTP: permisos, techos del plan, y la separación entre editar
 * un producto y cambiarle el precio.
 */

use App\Models\Catalog\ProductModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    enableModule($this->tenant, 'core');
    enableModule($this->tenant, 'catalog');

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $this->carlos, 'kitchen');
});

it('el dueño añade un producto a la carta', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $response = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => '  Reina   Pepiada  ',
            'price_cents' => 300,
            'prep_minutes' => 8,
        ]);

    $response->assertCreated()
        // El nombre llega normalizado: el dominio lo limpió antes de guardar.
        ->assertJsonPath('data.name', 'Reina Pepiada')
        ->assertJsonPath('data.priceCents', 300)
        ->assertJsonPath('data.prepMinutes', 8)
        // Sellado desde el primer día, para que «¿desde cuándo no reviso este
        // precio?» tenga respuesta.
        ->assertJsonPath('data.isActive', true);

    expect($response->json('data.priceUpdatedAt'))->not->toBeNull();
});

it('rechaza un precio negativo, y lo dice en el campo que toca', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada',
            'price_cents' => -100,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('price_cents');
});

it('la cocina no puede tocar la carta', function (): void {
    // Carlos sólo tiene la pantalla de comandas. Que no pueda editar precios
    // no es desconfianza: es que ése no es su trabajo y un toque accidental
    // con las manos llenas sale caro.
    entrarComo($this->slug, 'carlos@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Lo que sea',
            'price_cents' => 100,
        ])
        ->assertForbidden();
});

it('editar un producto NO puede cambiarle el precio', function (): void {
    // Si `price_cents` colara por aquí, el permiso aparte de cambiar precios
    // sería decorativo.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada', 'price_cents' => 300,
        ])->json('data.id');

    $this->withHeaders(browsingAs($this->slug))
        ->patchJson(urlFor($this->slug, "/api/v1/catalog/products/{$id}"), [
            'name' => 'Reina Pepiada Especial',
            'price_cents' => 1,          // ← se ignora, no se aplica
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Reina Pepiada Especial')
        ->assertJsonPath('data.priceCents', 300);
});

it('cambiar el precio deja rastro con el antes y el después', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada', 'price_cents' => 300,
        ])->json('data.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/catalog/products/{$id}/price"), ['price_cents' => 350])
        ->assertOk()
        ->assertJsonPath('data.priceCents', 350);

    actingForTenant($this->tenant);

    $entrada = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->where('action', 'catalog.price_changed')
        ->first();

    expect(json_decode((string) $entrada?->before, true))->toBe(['price_cents' => 300])
        ->and(json_decode((string) $entrada?->after, true))->toBe(['price_cents' => 350]);
});

it('poner el mismo precio no ensucia la bitácora ni la fecha', function (): void {
    // Una bitácora llena de «cambió de 3,00 a 3,00» es una bitácora que nadie
    // lee, y una fecha que se mueve sin motivo deja de servir para saber qué
    // lleva meses sin revisar.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $creado = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), [
            'name' => 'Reina Pepiada', 'price_cents' => 300,
        ])->json('data');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/catalog/products/{$creado['id']}/price"), ['price_cents' => 300])
        ->assertOk()
        ->assertJsonPath('data.priceUpdatedAt', $creado['priceUpdatedAt']);

    actingForTenant($this->tenant);

    expect(DB::table('audit_log')->where('action', 'catalog.price_changed')->count())->toBe(0);
});

it('el techo del plan frena, y dice cuántos caben', function (): void {
    // Un plan diminuto para no tener que crear sesenta productos.
    DB::table('plans')->insert([
        'code' => 'diminuto', 'name' => 'Diminuto', 'currency' => 'USD',
        'max_products' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('plan_modules')->insert([
        ['plan_code' => 'diminuto', 'module_code' => 'catalog'],
    ]);
    DB::table('tenants')->where('id', $this->tenant)->update(['plan_code' => 'diminuto']);

    entrarComo($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), ['name' => 'Primero', 'price_cents' => 100])
        ->assertCreated();

    $response = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/products'), ['name' => 'Segundo', 'price_cents' => 100])
        ->assertStatus(422);

    // El mensaje dice qué hacer, no sólo que no se puede.
    expect($response->json('message'))->toContain('1 productos');
});

it('un grupo de modificadores llega con su regla ya explicada', function (): void {
    // La misma frase la ven el portal, la caja y el bot. Tres validaciones
    // distintas serían tres oportunidades de que una se quede vieja.
    entrarComo($this->slug, 'maria@ejemplo.com');

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

it('un modificador SÍ puede descontar', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/catalog/modifier-groups'), [
            'name' => 'Extras',
            'modifiers' => [['name' => 'Sin queso', 'price_delta_cents' => -50]],
        ])->assertCreated();

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/catalog/modifier-groups'))
        ->assertJsonPath('data.0.modifiers.0.priceDeltaCents', -50);
});

it('la carta es de núcleo: nadie puede apagarla, ni escribiendo en la tabla', function (): void {
    // Un negocio de comida sin carta no es nada, y todo lo demás —pedidos,
    // cocina, caja, portal, bots— cuelga de aquí. Por eso `isCore()` la
    // enciende pase lo que pase en `tenant_modules`.
    //
    // Se escribe la fila a mano precisamente para comprobar que no basta.
    DB::table('tenant_modules')
        ->where('tenant_id', $this->tenant)
        ->where('module_code', 'catalog')
        ->update(['enabled' => false]);

    entrarComo($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/catalog/products'))
        ->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/me'))
        ->assertJsonPath('modules', fn (array $modules): bool => in_array('catalog', $modules, true));
});

it('un módulo que este negocio no tiene responde 404, no 403', function (): void {
    // 404 y no 403 a propósito: que un módulo no exista para un negocio es
    // información sobre su CONTRATO, no sobre sus permisos. Para una cocina
    // oculta que sólo vende por el portal, la caja sencillamente no existe —
    // no hay nada que explicarle al dueño. Un 403 diría «esto existe pero no
    // puedes», que invita a insistir y filtra qué funcionalidades hay.
    entrarComo($this->slug, 'maria@ejemplo.com');

    // `counter` llega en la Fase 5. Hoy no existe para nadie, que es
    // exactamente el caso que se quiere comprobar.
    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/counter/anything'))
        ->assertNotFound();
});

it('la foto se sube, reemplaza a la anterior y se puede quitar', function (): void {
    /*
     * En el portal la foto es lo que vende. Antes había que pegar una
     * dirección a mano, que en la práctica significaba que casi ninguna carta
     * tenía fotos.
     */
    Storage::fake('public');

    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    $producto = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $primera = test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$producto->id}/photo"), [
            'photo' => UploadedFile::fake()->create('arepa.jpg', 200, 'image/jpeg'),
        ])->assertOk()->json('data.photoUrl');

    // Ruta relativa: la sirve el mismo origen desde el que se abrió la página,
    // que es el subdominio del negocio y no el dominio raíz.
    expect($primera)->toStartWith("/storage/products/{$this->tenant}/");

    Storage::disk('public')->assertExists(str_replace('/storage/', '', $primera));

    $segunda = test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$producto->id}/photo"), [
            'photo' => UploadedFile::fake()->create('otra.jpg', 200, 'image/jpeg'),
        ])->assertOk()->json('data.photoUrl');

    // La anterior se borra: si no, cada cambio deja un archivo huérfano y el
    // disco de un VPS pequeño no está para guardar seis arepas iguales.
    expect($segunda)->not->toBe($primera);
    Storage::disk('public')->assertMissing(str_replace('/storage/', '', $primera));

    test()->withHeaders(browsingAs($this->slug))
        ->deleteJson(urlFor($this->slug, "/api/v1/catalog/products/{$producto->id}/photo"))
        ->assertStatus(204);

    actingForTenant($this->tenant);

    expect(ProductModel::find($producto->id)->photo_url)->toBeNull();
    Storage::disk('public')->assertMissing(str_replace('/storage/', '', $segunda));
});

it('lo que no es una foto no se sube', function (): void {
    Storage::fake('public');

    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    $producto = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$producto->id}/photo"), [
            'photo' => UploadedFile::fake()->create('cualquier.jpg', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
});

it('quien no maneja la carta no le cambia la foto a nada', function (): void {
    Storage::fake('public');

    actingForTenant($this->tenant);

    $producto = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $carlos = makeUser($this->tenant, 'carlos-foto@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    entrarComo($this->slug, 'carlos-foto@ejemplo.com');

    test()->withHeaders(browsingAs($this->slug))
        ->post(urlFor($this->slug, "/api/v1/catalog/products/{$producto->id}/photo"), [
            'photo' => UploadedFile::fake()->create('arepa.jpg', 100, 'image/jpeg'),
        ])->assertForbidden();
});
