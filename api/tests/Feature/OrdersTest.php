<?php

declare(strict_types=1);

/*
 * Los pedidos por HTTP: que el precio lo ponga el servidor, que el recorrido
 * completo funcione, y que dos personas tocando el mismo pedido no se pisen.
 */

use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    enableModule($this->tenant, 'core');
    enableModule($this->tenant, 'catalog');
    enableModule($this->tenant, 'orders');

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $grupo = ModifierGroupModel::create(['name' => 'Extras', 'min_choices' => 0, 'max_choices' => 3]);
    $this->extraQueso = $grupo->modifiers()->create(['name' => 'Extra queso', 'price_delta_cents' => 50]);
    $this->sinQueso = $grupo->modifiers()->create(['name' => 'Sin queso', 'price_delta_cents' => -50]);
});

function pedir(string $slug, array $body): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/orders'), $body);
}

function avanzar(string $slug, string $id, string $estado): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, "/api/v1/orders/{$id}/advance"), ['status' => $estado]);
}

it('el precio lo pone el SERVIDOR, aunque el cliente mande otro', function (): void {
    // Es la regla que gobierna todo este módulo. Sin ella, un navegador
    // manipulado se cobraría lo que quisiera y sólo se notaría al cuadrar el
    // mes.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $response = pedir($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 2,
            // Un cliente malicioso mandando su propio precio. Se ignora: el
            // endpoint ni siquiera lo acepta.
            'price_cents' => 1,
            'unit_price_cents' => 1,
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.totalCents', 600)
        ->assertJsonPath('data.items.0.unitPriceCents', 300);
});

it('los agregados se cobran por unidad', function (): void {
    // Dos arepas con extra queso llevan el extra dos veces.
    entrarComo($this->slug, 'maria@ejemplo.com');

    pedir($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 2,
            'modifier_ids' => [$this->extraQueso->id],
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.totalCents', 700)
        ->assertJsonPath('data.items.0.modifiers.0.name', 'Extra queso');
});

it('un agregado puede descontar', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    pedir($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1, 'modifier_ids' => [$this->sinQueso->id]]],
    ])->assertCreated()->assertJsonPath('data.totalCents', 250);
});

it('el nombre y el precio quedan COPIADOS: cambiar la carta no mueve un pedido viejo', function (): void {
    // Un ticket de hace seis meses debe decir lo que decía cuando se imprimió.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
    ])->json('data.id');

    actingForTenant($this->tenant);
    $this->arepa->update(['name' => 'Reina Pepiada Especial', 'price_cents' => 900]);

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, "/api/v1/orders/{$id}"))
        ->assertJsonPath('data.items.0.name', 'Reina Pepiada')
        ->assertJsonPath('data.totalCents', 300);
});

it('no se puede pedir algo que ya no está en la carta', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    actingForTenant($this->tenant);
    $this->arepa->update(['is_active' => false]);

    pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertStatus(422);
});

it('el recorrido completo: recibido → confirmado → listo → entregado', function (): void {
    // Éste es el criterio de salida de la fase.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertJsonPath('data.status', 'placed')
        ->json('data.id');

    avanzar($this->slug, $id, 'confirmed')->assertOk()->assertJsonPath('data.isInKitchen', true);
    avanzar($this->slug, $id, 'preparing')->assertOk();
    avanzar($this->slug, $id, 'ready')->assertOk()->assertJsonPath('data.isInKitchen', false);
    avanzar($this->slug, $id, 'delivered')
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.isOpen', false);
});

it('no se puede saltar la cocina', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    avanzar($this->slug, $id, 'confirmed')->assertOk();

    // 409 y no 422: los datos están bien, lo que pasa es que ese salto no
    // existe. El mensaje pide recargar.
    avanzar($this->slug, $id, 'delivered')->assertStatus(409);
});

it('el bloqueo optimista rechaza a quien llega con la versión vieja', function (): void {
    // La caja y la pantalla de cocina miran el mismo pedido y pulsan casi a la
    // vez todos los días. Sin esto, quien guarda segundo pisa lo del primero y
    // NADIE se entera.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    actingForTenant($this->tenant);
    $versionInicial = (int) DB::table('orders')->where('id', $id)->value('state_version');

    // Primera persona: confirma. La versión avanza.
    avanzar($this->slug, $id, 'confirmed')->assertOk();

    actingForTenant($this->tenant);
    expect((int) DB::table('orders')->where('id', $id)->value('state_version'))->toBe($versionInicial + 1);

    // Segunda persona: tenía la pantalla cargada de ANTES, así que su escritura
    // lleva la versión vieja. Es exactamente el UPDATE que hace el caso de uso,
    // y no afecta ninguna fila — que es como se entera de que llegó tarde.
    $afectadas = DB::table('orders')
        ->where('id', $id)
        ->where('state_version', $versionInicial)
        ->update(['status' => 'cancelled']);

    expect($afectadas)->toBe(0);

    // Y el pedido sigue donde lo dejó la primera persona.
    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('confirmed');
});

it('repetir el mismo paso no es un error', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    avanzar($this->slug, $id, 'confirmed')->assertOk();
    avanzar($this->slug, $id, 'confirmed')->assertOk();
});

it('se cobra mezclado: parte en efectivo y el resto en pago móvil', function (): void {
    // Es como se cobra aquí. Con una sola columna `payment_method` esto no se
    // representa y el cajero acaba anotando la mitad en observaciones.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 2]]])
        ->json('data.id');   // total: 600

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/payments"), [
            'method' => 'cash_usd', 'amount_cents' => 300,
        ])
        ->assertOk()
        ->assertJsonPath('data.paidCents', 300)
        ->assertJsonPath('data.paymentStatus', 'partial')
        ->assertJsonPath('data.outstandingCents', 300);

    // El pago móvil entra esperando revisión: no hay API bancaria que
    // preguntar, alguien mira el comprobante.
    $pago = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/payments"), [
            'method' => 'pago_movil', 'amount_cents' => 300, 'reference' => '004512',
        ])->assertOk();

    // Todavía debe 300: el pago móvil no cuenta hasta que se confirma.
    $pago->assertJsonPath('data.outstandingCents', 300);

    $pagoId = collect($pago->json('data.payments'))->firstWhere('method', 'pago_movil')['id'];

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/payments/{$pagoId}/confirm"))
        ->assertOk()
        ->assertJsonPath('data.paidCents', 600)
        ->assertJsonPath('data.paymentStatus', 'paid')
        ->assertJsonPath('data.outstandingCents', 0);
});

it('cancelar exige un motivo y queda en la bitácora', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/cancel"), [])
        ->assertStatus(422);

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/cancel"), [
            'reason' => 'El cliente se arrepintió',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    actingForTenant($this->tenant);

    $entrada = DB::table('audit_log')->where('action', 'orders.cancelled')->first();
    expect($entrada?->reason)->toBe('El cliente se arrepintió');
});

it('un pedido entregado no se puede cancelar', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $id = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $estado) {
        avanzar($this->slug, $id, $estado)->assertOk();
    }

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/cancel"), ['reason' => 'Ups'])
        ->assertStatus(409);
});

it('el tablero muestra sólo los pedidos vivos, del más viejo primero', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    $viejo = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');
    $nuevo = pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $estado) {
        avanzar($this->slug, $nuevo, $estado)->assertOk();
    }

    $tablero = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/orders'))
        ->assertOk()
        ->json('data');

    expect(collect($tablero)->pluck('id')->all())->toBe([$viejo]);
});

it('cada pedido lleva su número, y no se repite', function (): void {
    // Es el número que se grita en el mostrador: dos pedidos con el mismo
    // número son dos clientes recogiendo la comida del otro.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $numeros = [];
    for ($i = 0; $i < 3; $i++) {
        $numeros[] = pedir($this->slug, [
            'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        ])->json('data.number');
    }

    expect($numeros)->toBe([1, 2, 3]);
});

it('la cocina no puede tomar pedidos', function (): void {
    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $carlos, 'kitchen');

    entrarComo($this->slug, 'carlos@ejemplo.com');

    pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertForbidden();
});

it('la tasa del día queda congelada en el pedido', function (): void {
    // Sin esto, el importe en bolívares de un pedido de marzo cambiaría cada
    // mañana.
    actingForTenant($this->tenant);
    DB::table('exchange_rates')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'rate' => '36.500000',
        'source' => 'custom',
        'effective_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    entrarComo($this->slug, 'maria@ejemplo.com');

    pedir($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertCreated()
        ->assertJsonPath('data.exchangeRate', 36.5);
});
