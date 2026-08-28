<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Entities\Product;
use Modules\Catalog\Domain\Exceptions\InvalidPrice;
use Modules\Catalog\Domain\Exceptions\InvalidProductName;
use Modules\Catalog\Domain\ValueObjects\PrepTime;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;
use Shared\Domain\ValueObjects\Money;

function unProducto(int $precioCentavos = 300, ?StockPolicy $stock = null): Product
{
    return Product::create(
        id: 'p-1',
        name: 'Reina Pepiada',
        price: Money::fromCents($precioCentavos),
        stock: $stock,
        now: new DateTimeImmutable('2026-01-01 10:00:00'),
    );
}

it('normaliza los espacios del nombre', function (): void {
    // «Reina  Pepiada» y «Reina Pepiada» son el mismo producto para cualquiera
    // menos para una comparación de cadenas — y acaban siendo dos filas.
    $producto = Product::create('p-1', '  Reina   Pepiada  ', Money::fromCents(300));

    expect($producto->name()->value)->toBe('Reina Pepiada');
});

it('rechaza un nombre que no dice nada', function (): void {
    expect(fn () => Product::create('p-1', 'x', Money::fromCents(300)))
        ->toThrow(InvalidProductName::class);
});

it('rechaza un precio negativo', function (): void {
    // Un plato que cuesta menos que nada es siempre un error de tecleo, y
    // descubrirlo al cuadrar la caja es tarde.
    expect(fn () => Product::create('p-1', 'Reina Pepiada', Money::fromCents(-100)))
        ->toThrow(InvalidPrice::class);
});

it('sella la fecha del precio al nacer', function (): void {
    // Para que «¿desde cuándo no reviso este precio?» tenga respuesta desde el
    // primer día.
    expect(unProducto()->priceUpdatedAt())->not->toBeNull();
});

it('mueve la fecha del precio SÓLO si el precio cambió de verdad', function (): void {
    $producto = unProducto(300);
    $original = $producto->priceUpdatedAt();

    // Guardar el formulario sin tocar el precio no puede hacer parecer que se
    // revisó: esa fecha es justo lo que el dueño mira para saber qué lleva
    // meses sin ajustar, y ensuciarla la vuelve inútil.
    $producto->changePriceTo(Money::fromCents(300), new DateTimeImmutable('2026-06-01 10:00:00'));

    expect($producto->priceUpdatedAt())->toEqual($original);

    $producto->changePriceTo(Money::fromCents(350), new DateTimeImmutable('2026-06-01 10:00:00'));

    expect($producto->priceUpdatedAt()?->format('Y-m-d'))->toBe('2026-06-01')
        ->and($producto->price()->cents)->toBe(350);
});

it('un producto que se hace al momento siempre se puede vender', function (): void {
    // La mayoría de los platos no llevan cuenta de existencias: no tiene
    // sentido preguntarse cuántas arepas quedan.
    expect(unProducto()->isSellable(50))->toBeTrue();
});

it('un producto contado deja de venderse cuando se acaba', function (): void {
    $producto = unProducto(stock: StockPolicy::tracked(2));

    expect($producto->isSellable(2))->toBeTrue()
        ->and($producto->isSellable(3))->toBeFalse();
});

it('sacarlo de la carta lo hace no vendible, pero no lo borra', function (): void {
    // Nunca se borra un producto que ya se vendió: los pedidos viejos lo
    // referencian y una comanda de hace tres meses tiene que poder leerse.
    $producto = unProducto();
    $producto->deactivate();

    expect($producto->isSellable())->toBeFalse()
        ->and($producto->name()->value)->toBe('Reina Pepiada');
});

it('acepta que no todo lo que se vende se prepara', function (): void {
    // Una malta se saca de la nevera. Obligar a poner un tiempo a las bebidas
    // sería ruido en el formulario.
    expect(unProducto()->prepTime()->isKnown())->toBeFalse()
        ->and(PrepTime::ofMinutes(12)->seconds())->toBe(720);
});
