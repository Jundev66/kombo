<?php

declare(strict_types=1);

use Shared\Domain\ValueObjects\ExchangeRate;
use Shared\Domain\ValueObjects\Money;

it('convierte dólares a bolívares', function (): void {
    // 3,00 $ a 40 Bs/$ = 120,00 Bs
    $tasa = ExchangeRate::of(40);

    expect($tasa->toBolivars(Money::fromCents(300))->cents)->toBe(12000);
});

it('vuelve de bolívares a dólares', function (): void {
    // Para registrar un pago recibido en bolívares.
    $tasa = ExchangeRate::of(40);

    expect($tasa->toUsd(Money::fromCents(12000, 'VES'))->cents)->toBe(300);
});

it('marca el resultado como bolívares, no como dólares', function (): void {
    // Si la moneda no viajara con el importe, sumar un pago en bolívares a un
    // total en dólares sería una operación que el sistema aceptaría en
    // silencio.
    expect(ExchangeRate::of(40)->toBolivars(Money::fromCents(300))->currency)->toBe('VES');
});

it('acepta la coma decimal, que es como se escribe aquí', function (): void {
    expect(ExchangeRate::of('40,50')->asFloat())->toBe(40.5);
});

it('guarda seis decimales', function (): void {
    // Con menos, convertir un total grande arrastra un error visible en el
    // vuelto: suficiente para que un cuadre no cierre.
    expect(ExchangeRate::of('36.123456')->value)->toBe('36.123456');
});

it('rechaza una tasa de cero o negativa', function (): void {
    // Una tasa de cero convertiría todos los precios en cero, y el primero en
    // enterarse sería el cliente.
    expect(fn () => ExchangeRate::of(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExchangeRate::of(-5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExchangeRate::of('la de hoy'))->toThrow(InvalidArgumentException::class);
});

it('redondea una sola vez, al final', function (): void {
    // Redondear por línea y luego sumar es cómo aparece la diferencia de un
    // bolívar que nadie sabe de dónde salió.
    $tasa = ExchangeRate::of('36.5');

    expect($tasa->toBolivars(Money::fromCents(333))->cents)->toBe(12155);
});
