<?php

declare(strict_types=1);

use Shared\Domain\ValueObjects\ExchangeRate;
use Shared\Domain\ValueObjects\Money;

it('converts dollars to bolívares', function (): void {
    // $3.00 at 40 Bs/$ = 120.00 Bs
    $rate = ExchangeRate::of(40);

    expect($rate->toBolivars(Money::fromCents(300))->cents)->toBe(12000);
});

it('converts back from bolívares to dollars', function (): void {
    // For recording a payment received in bolívares.
    $rate = ExchangeRate::of(40);

    expect($rate->toUsd(Money::fromCents(12000, 'VES'))->cents)->toBe(300);
});

it('marks the result as bolívares, not as dollars', function (): void {
    // If the currency did not travel with the amount, adding a bolívar payment
    // to a dollar total would be an operation the system accepted in silence.
    expect(ExchangeRate::of(40)->toBolivars(Money::fromCents(300))->currency)->toBe('VES');
});

it('accepts the decimal comma, which is how it is written here', function (): void {
    expect(ExchangeRate::of('40,50')->asFloat())->toBe(40.5);
});

it('keeps six decimal places', function (): void {
    // With fewer, converting a large total carries an error visible in the
    // change: enough to stop a cash count balancing.
    expect(ExchangeRate::of('36.123456')->value)->toBe('36.123456');
});

it('rejects a zero or negative rate', function (): void {
    // A rate of zero would turn every price into zero, and the first to find
    // out would be the customer.
    expect(fn () => ExchangeRate::of(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExchangeRate::of(-5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExchangeRate::of('la de hoy'))->toThrow(InvalidArgumentException::class);
});

it('rounds once, at the end', function (): void {
    // Rounding per line and then summing is how the one-bolívar difference
    // nobody can account for appears.
    $rate = ExchangeRate::of('36.5');

    expect($rate->toBolivars(Money::fromCents(333))->cents)->toBe(12155);
});
