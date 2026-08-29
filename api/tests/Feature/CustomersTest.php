<?php

declare(strict_types=1);

/*
 * Los clientes.
 *
 * La ficha se llena sola: en un negocio de comida nadie va a rellenar un
 * formulario de cliente entre dos almuerzos.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Customers\CustomerModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Application\UseCases\PlaceOrder;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'customers'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
});

function pedirComo(string $productId, string $phone, ?string $name = null, int $quantity = 1): void
{
    app(PlaceOrder::class)->execute(
        items: [['product_id' => $productId, 'quantity' => $quantity]],
        channel: 'portal',
        customerName: $name,
        customerPhone: $phone,
    );
}

function clientes(string $slug, string $path = '', string $method = 'GET', array $body = []): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->json($method, urlFor($slug, "/api/v1/customers{$path}"), $body);
}

it('la ficha se llena sola con cada pedido', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '04141234567', 'Ana');
    pedirComo($this->arepa->id, '04141234567', 'Ana', quantity: 2);

    $data = clientes($this->slug)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Ana')
        ->and($data[0]['ordersCount'])->toBe(2)
        // 300 + 600: lo que lleva gastado, sin tener que sumar los pedidos
        // cada vez que se abre la lista.
        ->and($data[0]['spentCents'])->toBe(900);
});

it('sin teléfono no hay a quién recordar, y no pasa nada', function (): void {
    // En el mostrador la mayoría de la gente no lo deja, y está bien.
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'counter',
    );

    expect(clientes($this->slug)->json('data'))->toBe([]);
});

it('el teléfono se guarda CIFRADO', function (): void {
    // Una lista de teléfonos filtrada es exactamente lo que un competidor
    // querría.
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '04141234567', 'Ana');

    $crudo = (string) DB::table('customers')->value('phone');

    expect($crudo)->not->toContain('04141234567')
        ->and(CustomerModel::first()?->phone)->toBe('04141234567');
});

it('se busca por el número completo, aunque esté cifrado', function (): void {
    /*
     * El cifrado de Laravel no es determinista, así que no se puede buscar por
     * igualdad. Al lado va un hash con la clave de la aplicación: encuentra al
     * cliente sin poder leer el número.
     */
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '04141234567', 'Ana');
    pedirComo($this->arepa->id, '04149999999', 'Pedro');

    $encontrados = clientes($this->slug, '?buscar=04141234567')->json('data');

    expect($encontrados)->toHaveCount(1)
        ->and($encontrados[0]['name'])->toBe('Ana');
});

it('el número se busca escrito como sea', function (): void {
    // La gente lo copia del chat con guiones y espacios.
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '0414-123 4567', 'Ana');

    expect(clientes($this->slug, '?buscar=04141234567')->json('data'))->toHaveCount(1);
});

it('la ficha trae lo que ha pedido', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '04141234567', 'Ana');

    $id = clientes($this->slug)->json('data.0.id');

    $ficha = clientes($this->slug, "/{$id}")->assertOk()->json('data');

    expect($ficha['orders'])->toHaveCount(1)
        ->and($ficha['orders'][0]['totalCents'])->toBe(300);
});

it('la nota se escribe a mano, y lo demás lo lleva el sistema', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '04141234567', 'Ana');

    $id = clientes($this->slug)->json('data.0.id');

    clientes($this->slug, "/{$id}", 'PATCH', ['notes' => 'No le pongan cebolla'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'No le pongan cebolla');
});

it('el nombre mejora con el tiempo, y no lo pisa un pedido sin nombre', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '04141234567', 'ana');
    pedirComo($this->arepa->id, '04141234567', 'Ana Pérez');
    pedirComo($this->arepa->id, '04141234567');

    expect(clientes($this->slug)->json('data.0.name'))->toBe('Ana Pérez');
});

it('los clientes de un negocio no son los de otro', function (): void {
    $sufijo = Str::lower(Str::random(6));
    $vecino = makeTenant("vecino-{$sufijo}", plan: 'negocio');

    actingForTenant($vecino);
    foreach (['core', 'catalog', 'orders', 'customers'] as $modulo) {
        enableModule($vecino, $modulo);
    }

    $pizza = ProductModel::create(['name' => 'Margarita', 'price_cents' => 900]);
    pedirComo($pizza->id, '04140000000', 'Cliente del vecino');

    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    pedirComo($this->arepa->id, '04141234567', 'Ana');

    $nombres = array_column(clientes($this->slug)->json('data'), 'name');

    expect($nombres)->toBe(['Ana']);
});

it('un negocio sin el módulo no tiene clientes', function (): void {
    $sufijo = Str::lower(Str::random(6));
    $slug = "sinclientes-{$sufijo}";
    $otro = makeTenant($slug, plan: 'inicial');

    actingForTenant($otro);
    foreach (['core', 'catalog', 'orders'] as $modulo) {
        enableModule($otro, $modulo);
    }

    $pedro = makeUser($otro, 'pedro@ejemplo.com', 'Pedro');
    giveRole($otro, $pedro, 'owner');

    entrarComo($slug, 'pedro@ejemplo.com');

    clientes($slug)->assertNotFound();

    // Y el oyente se calla: sin módulo no se escribe ni una fila.
    actingForTenant($otro);

    $producto = ProductModel::create(['name' => 'Algo', 'price_cents' => 100]);
    pedirComo($producto->id, '04141111111', 'Nadie');

    expect(DB::table('customers')->count())->toBe(0);
});

it('la cocina no ve la libreta de clientes', function (): void {
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    entrarComo($this->slug, 'carlos@ejemplo.com');

    clientes($this->slug)->assertForbidden();
});
