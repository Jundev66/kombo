<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Entities\Product;
use Modules\Catalog\Domain\Exceptions\InvalidPrice;
use Modules\Catalog\Domain\Exceptions\InvalidProductName;
use Modules\Catalog\Domain\ValueObjects\PrepTime;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;
use Shared\Domain\ValueObjects\Money;

function aProduct(int $priceCents = 300, ?StockPolicy $stock = null): Product
{
    return Product::create(
        id: 'p-1',
        name: 'Reina Pepiada',
        price: Money::fromCents($priceCents),
        stock: $stock,
        now: new DateTimeImmutable('2026-01-01 10:00:00'),
    );
}

it('normalises the whitespace in the name', function (): void {
    // "Reina  Pepiada" and "Reina Pepiada" are the same product to everybody
    // except a string comparison — and end up as two rows.
    $product = Product::create('p-1', '  Reina   Pepiada  ', Money::fromCents(300));

    expect($product->name()->value)->toBe('Reina Pepiada');
});

it('rejects a name that says nothing', function (): void {
    expect(fn () => Product::create('p-1', 'x', Money::fromCents(300)))
        ->toThrow(InvalidProductName::class);
});

it('rejects a negative price', function (): void {
    // A dish costing less than nothing is always a typo, and finding out at
    // cash-up time is too late.
    expect(fn () => Product::create('p-1', 'Reina Pepiada', Money::fromCents(-100)))
        ->toThrow(InvalidPrice::class);
});

it('stamps the price date at birth', function (): void {
    // So "how long since I reviewed this price?" has an answer from day one.
    expect(aProduct()->priceUpdatedAt())->not->toBeNull();
});

it('moves the price date ONLY if the price really changed', function (): void {
    $product = aProduct(300);
    $original = $product->priceUpdatedAt();

    // Saving the form without touching the price cannot make it look reviewed:
    // that date is what the owner scans for what has gone months without
    // adjustment, and dirtying it makes it useless.
    $product->changePriceTo(Money::fromCents(300), new DateTimeImmutable('2026-06-01 10:00:00'));

    expect($product->priceUpdatedAt())->toEqual($original);

    $product->changePriceTo(Money::fromCents(350), new DateTimeImmutable('2026-06-01 10:00:00'));

    expect($product->priceUpdatedAt()?->format('Y-m-d'))->toBe('2026-06-01')
        ->and($product->price()->cents)->toBe(350);
});

it('a made-to-order product can always be sold', function (): void {
    // Most dishes do not track stock: asking how many arepas are left makes no
    // sense.
    expect(aProduct()->isSellable(50))->toBeTrue();
});

it('a counted product stops selling when it runs out', function (): void {
    $product = aProduct(stock: StockPolicy::tracked(2));

    expect($product->isSellable(2))->toBeTrue()
        ->and($product->isSellable(3))->toBeFalse();
});

it('taking it off the menu makes it unsellable, but does not delete it', function (): void {
    // A product that has been sold is never deleted: old orders reference it
    // and a ticket from three months ago has to stay readable.
    $product = aProduct();
    $product->deactivate();

    expect($product->isSellable())->toBeFalse()
        ->and($product->name()->value)->toBe('Reina Pepiada');
});

it('accepts that not everything sold is prepared', function (): void {
    // A malta comes out of the fridge. Forcing a time onto drinks would be
    // noise in the form.
    expect(aProduct()->prepTime()->isKnown())->toBeFalse()
        ->and(PrepTime::ofMinutes(12)->seconds())->toBe(720);
});
