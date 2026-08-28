<?php

declare(strict_types=1);

/*
 * La carta de un negocio no existe para otro.
 *
 * Es el dato más sensible que hay aquí después del dinero: lo que vendes y a
 * cómo. Si el sistema filtrara eso, el de al lado sabría exactamente cuánto
 * ganas por arepa.
 */

use App\Models\Catalog\ProductModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$sufijo}");
    $this->pizzeria = makeTenant("laesquina-{$sufijo}");

    actingForTenant($this->arepera);
    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    actingForTenant($this->pizzeria);
    $this->pizza = ProductModel::create(['name' => 'Margarita', 'price_cents' => 800]);
});

it('cada negocio ve sólo su propia carta', function (): void {
    actingForTenant($this->arepera);
    expect(ProductModel::pluck('name')->all())->toBe(['Reina Pepiada']);

    actingForTenant($this->pizzeria);
    expect(ProductModel::pluck('name')->all())->toBe(['Margarita']);
});

it('no se ve el producto de otro ni pidiéndolo por su identificador', function (): void {
    // El caso realista: alguien copia un id de una URL y lo prueba en otra
    // cuenta. Tiene que responder «no existe», no «no puedes».
    actingForTenant($this->arepera);

    expect(ProductModel::find($this->pizza->id))->toBeNull();
});

it('el aislamiento aguanta aunque se desactive el filtro de Eloquent', function (): void {
    // `acrossTenants()` quita el ámbito global. Row Level Security sigue
    // puesta, así que como `kombo_app` esto devuelve exactamente lo mismo.
    //
    // Ésta es LA prueba de defensa en profundidad: comprueba que la segunda
    // capa aguanta sola cuando la primera se cae.
    actingForTenant($this->arepera);

    expect(ProductModel::acrossTenants()->count())->toBe(1);
});

it('no se puede colar un producto en la carta de otro negocio', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('products')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,   // ← el negocio de OTRO
        'name' => 'Colada',
        'price_cents' => 100,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('la clave foránea compuesta impide meter un producto en la categoría de otro', function (): void {
    // Con una clave foránea simple esto sería una fila perfectamente válida, y
    // el error se descubriría meses después cuando un reporte no cuadre.
    actingForTenant($this->pizzeria);
    $categoriaAjena = DB::table('categories')->insertGetId([
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
        'category_id' => $categoriaAjena,   // ← categoría de otro negocio
        'name' => 'Arepa mal colocada',
        'price_cents' => 100,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
