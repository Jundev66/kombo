<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Exceptions\InvalidStock;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;

it('sin cuenta de existencias, siempre alcanza', function (): void {
    expect(StockPolicy::untracked()->allows(1000))->toBeTrue()
        ->and(StockPolicy::untracked()->isSoldOut())->toBeFalse();
});

it('con cuenta, alcanza hasta donde alcance', function (): void {
    $stock = StockPolicy::tracked(3);

    expect($stock->allows(3))->toBeTrue()
        ->and($stock->allows(4))->toBeFalse();
});

it('descartar la cantidad cuando no se lleva la cuenta evita el estado imposible', function (): void {
    // «No lleva cuenta pero quedan 7» es lo que hace que una pantalla muestre
    // existencias de algo que nunca se contó.
    $stock = StockPolicy::from(tracked: false, quantity: 7);

    expect($stock->tracked)->toBeFalse()
        ->and($stock->quantity)->toBeNull();
});

it('si lleva la cuenta, hay que decir cuánto queda', function (): void {
    expect(fn () => StockPolicy::from(tracked: true, quantity: null))
        ->toThrow(InvalidStock::class);
});

it('nunca queda menos de cero', function (): void {
    // Vender de más no puede dejar un número negativo en la pantalla: si dos
    // cajas venden el último a la vez, queda cero, no menos uno.
    expect(StockPolicy::tracked(1)->decrease(5)->quantity)->toBe(0)
        ->and(fn () => StockPolicy::tracked(-1))->toThrow(InvalidStock::class);
});

it('descontar de algo sin cuenta no hace nada', function (): void {
    expect(StockPolicy::untracked()->decrease(3)->tracked)->toBeFalse();
});

it('sabe cuándo se acabó', function (): void {
    expect(StockPolicy::tracked(0)->isSoldOut())->toBeTrue()
        ->and(StockPolicy::tracked(1)->isSoldOut())->toBeFalse();
});
