<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Exceptions\InvalidStock;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;

it('with stock untracked, there is always enough', function (): void {
    expect(StockPolicy::untracked()->allows(1000))->toBeTrue()
        ->and(StockPolicy::untracked()->isSoldOut())->toBeFalse();
});

it('tracked, there is enough until there is not', function (): void {
    $stock = StockPolicy::tracked(3);

    expect($stock->allows(3))->toBeTrue()
        ->and($stock->allows(4))->toBeFalse();
});

it('discarding the quantity when stock is untracked avoids the impossible state', function (): void {
    // "Untracked but 7 left" is what makes a screen show stock for something
    // that was never counted.
    $stock = StockPolicy::from(tracked: false, quantity: 7);

    expect($stock->tracked)->toBeFalse()
        ->and($stock->quantity)->toBeNull();
});

it('if stock is tracked, how much is left has to be stated', function (): void {
    expect(fn () => StockPolicy::from(tracked: true, quantity: null))
        ->toThrow(InvalidStock::class);
});

it('never lands below zero', function (): void {
    // Overselling cannot leave a negative on the screen: if two tills sell the
    // last one at once, it lands on zero, not minus one.
    expect(StockPolicy::tracked(1)->decrease(5)->quantity)->toBe(0)
        ->and(fn () => StockPolicy::tracked(-1))->toThrow(InvalidStock::class);
});

it('deducting from something untracked does nothing', function (): void {
    expect(StockPolicy::untracked()->decrease(3)->tracked)->toBeFalse();
});

it('knows when it has run out', function (): void {
    expect(StockPolicy::tracked(0)->isSoldOut())->toBeTrue()
        ->and(StockPolicy::tracked(1)->isSoldOut())->toBeFalse();
});
