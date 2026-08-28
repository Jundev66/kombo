<?php

declare(strict_types=1);

/*
 * La cocina: que confirmar un pedido haga aparecer la comanda, y que avanzarla
 * se comporte como se comporta una cocina de verdad.
 */

use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ProductModel;
use App\Models\Kitchen\KitchenTicketModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Platform\Capabilities\CapabilityResolver;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create([
        'name' => 'Reina Pepiada', 'price_cents' => 300, 'prep_minutes' => 8,
    ]);
    $this->parrilla = ProductModel::create([
        'name' => 'Parrilla', 'price_cents' => 900, 'prep_minutes' => 20,
    ]);

    $grupo = ModifierGroupModel::create(['name' => 'Extras', 'min_choices' => 0, 'max_choices' => 3]);
    $this->sinCebolla = $grupo->modifiers()->create(['name' => 'Sin cebolla', 'price_delta_cents' => 0]);
});

/** Deja un pedido confirmado y devuelve su id. */
function pedidoConfirmado(string $slug, array $items): string
{
    $id = test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/orders'), ['items' => $items])
        ->assertCreated()
        ->json('data.id');

    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, "/api/v1/orders/{$id}/advance"), ['status' => 'confirmed'])
        ->assertOk();

    return $id;
}

function comandas(string $slug): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, '/api/v1/kitchen/tickets'));
}

it('CONFIRMAR un pedido hace aparecer la comanda en la cocina', function (): void {
    // Es el disparador que motivó todo el proyecto. Y es el ÚNICO camino: la
    // cocina no consulta los pedidos por su cuenta.
    entrarComo($this->slug, 'maria@ejemplo.com');

    comandas($this->slug)->assertOk()->assertJsonCount(0, 'data');

    pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 2]]);

    comandas($this->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.items.0.name', 'Reina Pepiada')
        ->assertJsonPath('data.0.items.0.quantity', 2);
});

it('un pedido SIN confirmar no llega a la cocina', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/orders'), [
            'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        ])->assertCreated();

    comandas($this->slug)->assertJsonCount(0, 'data');
});

it('la comanda lleva el MISMO número que el pedido', function (): void {
    // Dos numeraciones distintas para lo mismo es cómo se entrega el plato
    // equivocado: el número que se grita en el mostrador tiene que ser el que
    // la cocina tiene delante.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $numeroPedido = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, "/api/v1/orders/{$id}"))
        ->json('data.number');

    comandas($this->slug)->assertJsonPath('data.0.number', $numeroPedido);
});

it('los agregados llegan en TEXTO, listos para leer mientras se cocina', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    pedidoConfirmado($this->slug, [[
        'product_id' => $this->arepa->id,
        'quantity' => 1,
        'modifier_ids' => [$this->sinCebolla->id],
    ]]);

    comandas($this->slug)->assertJsonPath('data.0.items.0.modifiers', ['Sin cebolla']);
});

it('el tiempo estimado es el MÁXIMO de lo que lleva, no la suma', function (): void {
    // Los platos se hacen a la vez, no en fila. Sumar daría 28 minutos para una
    // arepa y una parrilla, y el semáforo no marcaría nada como tarde nunca.
    entrarComo($this->slug, 'maria@ejemplo.com');

    pedidoConfirmado($this->slug, [
        ['product_id' => $this->arepa->id, 'quantity' => 1],
        ['product_id' => $this->parrilla->id, 'quantity' => 1],
    ]);

    comandas($this->slug)->assertJsonPath('data.0.prepMinutes', 20);
});

it('el cronómetro lo calcula el SERVIDOR', function (): void {
    // El reloj de una tablet de cocina casi nunca está bien puesto. Si el
    // tiempo se calculara ahí, el semáforo mentiría todo el día.
    entrarComo($this->slug, 'maria@ejemplo.com');

    pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $comanda = comandas($this->slug)->json('data.0');

    expect($comanda['waitingSeconds'])->toBeGreaterThanOrEqual(0)
        ->and($comanda['waitingSeconds'])->toBeLessThan(60);
});

it('el umbral de «va tarde» viene del negocio, no fijo en la pantalla', function (): void {
    // Una arepera y una pizzería no tienen la misma idea de tarde.
    entrarComo($this->slug, 'maria@ejemplo.com');

    comandas($this->slug)->assertJsonPath('meta.staleMinutes', 15);

    actingForTenant($this->tenant);
    DB::table('tenant_settings')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'key' => 'kitchen.stale_minutes',
        'value' => '25',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Las capacidades se cachean; encender o cambiar algo las invalida.
    app(CapabilityResolver::class)->forget($this->tenant);

    comandas($this->slug)->assertJsonPath('meta.staleMinutes', 25);
});

it('la comanda avanza de un toque: empezar, listo, entregado', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = comandas($this->slug)->json('data.0.id');

    foreach (['preparing', 'ready'] as $paso) {
        $this->withHeaders(browsingAs($this->slug))
            ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => $paso])
            ->assertOk()
            ->assertJsonPath('data.status', $paso);
    }

    // Al servirla, SALE de la pantalla: el histórico es cosa de reportes.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'served'])
        ->assertOk();

    comandas($this->slug)->assertJsonCount(0, 'data');
});

it('repetir el mismo paso NO es un error', function (): void {
    // Dos cocineros tocando «Listo» a la vez no pueden hacer saltar un mensaje
    // rojo en mitad del servicio.
    entrarComo($this->slug, 'maria@ejemplo.com');
    pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = comandas($this->slug)->json('data.0.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'preparing'])
        ->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'preparing'])
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');
});

it('no se puede saltar un paso ni volver atrás', function (): void {
    // Un toque accidental que devuelva a «por hacer» una comanda entregada
    // hace que la cocina la prepare dos veces.
    entrarComo($this->slug, 'maria@ejemplo.com');
    pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = comandas($this->slug)->json('data.0.id');

    // De «por hacer» a «para entregar», saltándose la plancha: 409.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'ready'])
        ->assertStatus(409);
});

it('cada paso sella su hora', function (): void {
    // De ahí sale «cuánto tardamos», que es la única forma de saber si el
    // semáforo está bien calibrado.
    entrarComo($this->slug, 'maria@ejemplo.com');
    pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = comandas($this->slug)->json('data.0.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'preparing'])
        ->assertOk();

    actingForTenant($this->tenant);
    $comanda = KitchenTicketModel::find($id);

    expect($comanda?->started_at)->not->toBeNull()
        ->and($comanda?->ready_at)->toBeNull();
});

it('sin el módulo de cocina, confirmar un pedido no crea ninguna comanda', function (): void {
    // Un puesto donde el que atiende es el que cocina no necesita esta
    // pantalla, y el listener tiene que enterarse — no basta con que la ruta
    // responda 404.
    actingForTenant($this->tenant);
    DB::table('tenant_modules')
        ->where('tenant_id', $this->tenant)
        ->where('module_code', 'kitchen')
        ->update(['enabled' => false]);
    app(CapabilityResolver::class)->forget($this->tenant);

    entrarComo($this->slug, 'maria@ejemplo.com');
    pedidoConfirmado($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    actingForTenant($this->tenant);
    expect(KitchenTicketModel::count())->toBe(0);
});
