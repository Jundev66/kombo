<?php

declare(strict_types=1);

/*
 * One tenant's orders do not exist for another.
 *
 * The most sensitive data in the system: how much they sell, to whom and when.
 * And ids travel in URLs, so trying somebody else's is the first thing anyone
 * would do.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$suffix}");
    $this->pizzeria = makeTenant("laesquina-{$suffix}");

    actingForTenant($this->arepera);
    $arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->areperaOrder = OrderModel::create([
        'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
    ]);
    $this->areperaOrder->items()->create([
        'product_id' => $arepa->id, 'product_name' => 'Reina Pepiada',
        'unit_price_cents' => 300, 'quantity' => 1, 'line_total_cents' => 300,
    ]);

    actingForTenant($this->pizzeria);
    $this->pizzeriaOrder = OrderModel::create([
        'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 800, 'placed_at' => now(),
    ]);
});

it('each tenant sees only its own orders', function (): void {
    actingForTenant($this->arepera);
    expect(OrderModel::pluck('id')->all())->toBe([$this->areperaOrder->id]);

    actingForTenant($this->pizzeria);
    expect(OrderModel::pluck('id')->all())->toBe([$this->pizzeriaOrder->id]);
});

it('both can have order number 1', function (): void {
    // The sequence is PER TENANT. Global, the number shouted across the counter
    // would depend on how many orders the neighbour had taken.
    actingForTenant($this->arepera);
    expect(OrderModel::first()?->number)->toBe(1);

    actingForTenant($this->pizzeria);
    expect(OrderModel::first()?->number)->toBe(1);
});

it('another tenant\'s order is invisible even with its id', function (): void {
    actingForTenant($this->arepera);

    expect(OrderModel::find($this->pizzeriaOrder->id))->toBeNull();
});

it('another tenant\'s order lines are not visible either', function (): void {
    // Even when the order is invisible, its lines are another table: isolation
    // that depended on walking the relation would leak what is sold and at what
    // price.
    actingForTenant($this->pizzeria);

    expect(DB::table('order_items')->count())->toBe(0);
});

it('an order cannot be slipped into another tenant', function (): void {
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

it('the composite foreign key stops a line joining another tenant\'s order', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('order_items')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->arepera,
        'order_id' => $this->pizzeriaOrder->id,   // ← another tenant's order
        'product_name' => 'Colada',
        'unit_price_cents' => 100,
        'quantity' => 1,
        'line_total_cents' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
