<?php

declare(strict_types=1);

use Shared\Domain\ValueObjects\Money;

it('stores whole cents, never floats', function (): void {
    expect(Money::fromAmount('12,50')->cents)->toBe(1250)
        ->and(Money::fromAmount('12.50')->cents)->toBe(1250)
        ->and(Money::fromAmount(3)->cents)->toBe(300);
});

it('rounds when converting, it does not truncate', function (): void {
    // (int) 2.999999 gives 299: the error that turns a total of 3.00 into 2.99
    // with nobody able to find where it came from.
    expect(Money::fromAmount(2.999999)->cents)->toBe(300);
});

it('adds and subtracts with no floating-point surprises', function (): void {
    $total = Money::fromAmount('0.10')->plus(Money::fromAmount('0.20'));

    // 0.1 + 0.2 !== 0.3 in floating point. In whole cents it does.
    expect($total->cents)->toBe(30)
        ->and($total->equals(Money::fromAmount('0.30')))->toBeTrue();
});

it('multiplies by a fractional quantity, rounding once', function (): void {
    // Half a kilo of cheese at 8.30 a kilo.
    expect(Money::fromCents(830)->times(0.5)->cents)->toBe(415);
});

it('splits without losing or gaining a cent', function (): void {
    $parts = Money::fromCents(100)->split(3);

    expect(array_map(fn (Money $m): int => $m->cents, $parts))->toBe([34, 33, 33])
        ->and(array_sum(array_map(fn (Money $m): int => $m->cents, $parts)))->toBe(100);
});

it('splits negative amounts too without going out of balance', function (): void {
    $parts = Money::fromCents(-100)->split(3);

    expect(array_sum(array_map(fn (Money $m): int => $m->cents, $parts)))->toBe(-100);
});

it('refuses to mix currencies without a rate', function (): void {
    // Adding dollars and bolívares without saying at what rate is exactly what
    // makes a six-month report mean nothing.
    expect(fn () => Money::fromCents(100, 'USD')->plus(Money::fromCents(100, 'VES')))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an amount that is not a number', function (): void {
    expect(fn () => Money::fromAmount('doce con cincuenta'))
        ->toThrow(InvalidArgumentException::class);
});

it('formats for display, with local separators', function (): void {
    expect(Money::fromCents(1234567)->format())->toBe('12.345,67')
        ->and(Money::fromCents(-500)->format())->toBe('-5,00')
        ->and(Money::fromCents(5)->format())->toBe('0,05');
});
