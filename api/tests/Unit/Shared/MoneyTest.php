<?php

declare(strict_types=1);

use Shared\Domain\ValueObjects\Money;

it('guarda centavos enteros, nunca flotantes', function (): void {
    expect(Money::fromAmount('12,50')->cents)->toBe(1250)
        ->and(Money::fromAmount('12.50')->cents)->toBe(1250)
        ->and(Money::fromAmount(3)->cents)->toBe(300);
});

it('redondea al convertir, no trunca', function (): void {
    // (int) 2.999999 daría 299. Es el error que hace que un total de 3,00
    // aparezca como 2,99 y que nadie encuentre de dónde salió.
    expect(Money::fromAmount(2.999999)->cents)->toBe(300);
});

it('suma y resta sin sorpresas de coma flotante', function (): void {
    $total = Money::fromAmount('0.10')->plus(Money::fromAmount('0.20'));

    // 0.1 + 0.2 !== 0.3 en coma flotante. En centavos enteros, sí.
    expect($total->cents)->toBe(30)
        ->and($total->equals(Money::fromAmount('0.30')))->toBeTrue();
});

it('multiplica por una cantidad decimal redondeando una sola vez', function (): void {
    // Medio kilo de queso a 8,30 el kilo.
    expect(Money::fromCents(830)->times(0.5)->cents)->toBe(415);
});

it('reparte sin perder ni ganar un centimo', function (): void {
    $parts = Money::fromCents(100)->split(3);

    expect(array_map(fn (Money $m): int => $m->cents, $parts))->toBe([34, 33, 33])
        ->and(array_sum(array_map(fn (Money $m): int => $m->cents, $parts)))->toBe(100);
});

it('reparte tambien importes negativos sin descuadrar', function (): void {
    $parts = Money::fromCents(-100)->split(3);

    expect(array_sum(array_map(fn (Money $m): int => $m->cents, $parts)))->toBe(-100);
});

it('se niega a mezclar monedas sin una tasa', function (): void {
    // Sumar dólares y bolívares sin decir a qué tasa es exactamente el error
    // que hace que un reporte de seis meses no signifique nada.
    expect(fn () => Money::fromCents(100, 'USD')->plus(Money::fromCents(100, 'VES')))
        ->toThrow(InvalidArgumentException::class);
});

it('rechaza un importe que no es un numero', function (): void {
    expect(fn () => Money::fromAmount('doce con cincuenta'))
        ->toThrow(InvalidArgumentException::class);
});

it('formatea para mostrar, con separadores de aca', function (): void {
    expect(Money::fromCents(1234567)->format())->toBe('12.345,67')
        ->and(Money::fromCents(-500)->format())->toBe('-5,00')
        ->and(Money::fromCents(5)->format())->toBe('0,05');
});
