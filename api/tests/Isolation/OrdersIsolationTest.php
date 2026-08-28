<?php

declare(strict_types=1);

/*
 * Los pedidos de un negocio no existen para otro.
 *
 * Es el dato más sensible que hay en el sistema: cuánto vende, a quién y a qué
 * hora. Y los identificadores viajan en URLs, así que probar el de otro es lo
 * primero que haría cualquiera.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$sufijo}");
    $this->pizzeria = makeTenant("laesquina-{$sufijo}");

    actingForTenant($this->arepera);
    $arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->pedidoArepera = OrderModel::create([
        'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
    ]);
    $this->pedidoArepera->items()->create([
        'product_id' => $arepa->id, 'product_name' => 'Reina Pepiada',
        'unit_price_cents' => 300, 'quantity' => 1, 'line_total_cents' => 300,
    ]);

    actingForTenant($this->pizzeria);
    $this->pedidoPizzeria = OrderModel::create([
        'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 800, 'placed_at' => now(),
    ]);
});

it('cada negocio ve sólo sus propios pedidos', function (): void {
    actingForTenant($this->arepera);
    expect(OrderModel::pluck('id')->all())->toBe([$this->pedidoArepera->id]);

    actingForTenant($this->pizzeria);
    expect(OrderModel::pluck('id')->all())->toBe([$this->pedidoPizzeria->id]);
});

it('los dos pueden tener el pedido número 1', function (): void {
    // El correlativo es POR NEGOCIO. Si fuera global, el número que se grita
    // en el mostrador dependería de cuántos pedidos llevara el vecino.
    actingForTenant($this->arepera);
    expect(OrderModel::first()?->number)->toBe(1);

    actingForTenant($this->pizzeria);
    expect(OrderModel::first()?->number)->toBe(1);
});

it('no se ve el pedido de otro ni con su identificador', function (): void {
    actingForTenant($this->arepera);

    expect(OrderModel::find($this->pedidoPizzeria->id))->toBeNull();
});

it('las líneas de un pedido ajeno tampoco se ven', function (): void {
    // Aunque el pedido no se vea, sus líneas son otra tabla: si el aislamiento
    // dependiera de recorrer la relación, aquí se filtraría lo que se vende y
    // a cuánto.
    actingForTenant($this->pizzeria);

    expect(DB::table('order_items')->count())->toBe(0);
});

it('no se puede colar un pedido en otro negocio', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('orders')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,
        'number' => 99,
        'public_token' => Str::random(22),
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('la clave foránea compuesta impide meter una línea en el pedido de otro', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('order_items')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->arepera,
        'order_id' => $this->pedidoPizzeria->id,   // ← pedido de otro negocio
        'product_name' => 'Colada',
        'unit_price_cents' => 100,
        'quantity' => 1,
        'line_total_cents' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
