<?php

declare(strict_types=1);

/*
 * One tenant's menu does not exist for another.
 *
 * The most sensitive data here after the money: what you sell and for how much.
 */

use App\Models\Catalog\ProductModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$suffix}");
    $this->pizzeria = makeTenant("laesquina-{$suffix}");

    actingForTenant($this->arepera);
    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    actingForTenant($this->pizzeria);
    $this->pizza = ProductModel::create(['name' => 'Margarita', 'price_cents' => 800]);
});

it('each tenant sees only its own menu', function (): void {
    actingForTenant($this->arepera);
    expect(ProductModel::pluck('name')->all())->toBe(['Reina Pepiada']);

    actingForTenant($this->pizzeria);
    expect(ProductModel::pluck('name')->all())->toBe(['Margarita']);
});

it('another tenant\'s product is invisible even when asked for by id', function (): void {
    // The realistic case: somebody copies an id from a URL and tries it on
    // another account. It has to answer "does not exist", not "you may not".
    actingForTenant($this->arepera);

    expect(ProductModel::find($this->pizza->id))->toBeNull();
});

it('isolation holds even with Eloquent\'s filter disabled', function (): void {
    // `acrossTenants()` drops the global scope. RLS is still in place, so as
    // `kombo_app` this returns exactly the same rows — THE defence-in-depth
    // test: the second layer holding alone when the first falls.
    actingForTenant($this->arepera);

    expect(ProductModel::acrossTenants()->count())->toBe(1);
});

it('a product cannot be slipped into another tenant\'s menu', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('products')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,   // ← ANOTHER tenant's
        'name' => 'Colada',
        'price_cents' => 100,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('the composite foreign key stops a product joining another tenant\'s category', function (): void {
    // With a simple foreign key this would be a perfectly valid row, found out
    // months later when a report does not add up.
    actingForTenant($this->pizzeria);
    $otherTenantCategory = DB::table('categories')->insertGetId([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,
        'name' => 'Pizzas',
        'created_at' => now(),
        'updated_at' => now(),
    ], 'id');

    actingForTenant($this->arepera);

    expect(fn () => DB::table('products')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->arepera,
        'category_id' => $otherTenantCategory,   // ← another tenant's category
        'name' => 'Arepa mal colocada',
        'price_cents' => 100,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
