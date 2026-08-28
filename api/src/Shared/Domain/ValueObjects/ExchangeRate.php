<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * La tasa del día: cuántos bolívares vale un dólar.
 *
 * **El dólar es la moneda de VALOR** —precios, totales y reportes viven en él—
 * y **el bolívar es moneda de COBRO y presentación**: se calcula al momento y
 * se congela en el documento que se emitió.
 *
 * Esa asimetría es la que hace que un reporte de seis meses signifique algo. Si
 * los precios vivieran en bolívares, comparar marzo con septiembre sería
 * comparar dos monedas distintas con el mismo nombre.
 *
 * Y por eso la tasa se guarda EN CADA documento: la nota de entrega de marzo
 * tiene que seguir diciendo lo que decía en marzo, aunque hoy la tasa sea otra.
 */
final readonly class ExchangeRate
{
    /**
     * Seis decimales. Con menos, convertir un total grande arrastra un error
     * visible: a 4 decimales, una venta de 500 $ puede bailar varios bolívares
     * — suficiente para que un cuadre de caja no cierre.
     */
    private const SCALE = 6;

    private function __construct(public string $value) {}

    public static function of(string|float|int $rate): self
    {
        $normalized = is_string($rate) ? str_replace(',', '.', trim($rate)) : (string) $rate;

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("«{$rate}» no es una tasa válida.");
        }

        if ((float) $normalized <= 0) {
            // Una tasa de cero convertiría todos los precios en cero, y el
            // primero en enterarse sería el cliente.
            throw new InvalidArgumentException('La tasa tiene que ser mayor que cero.');
        }

        return new self(number_format((float) $normalized, self::SCALE, '.', ''));
    }

    /**
     * De dólares a bolívares.
     *
     * Redondea UNA sola vez, al final. Redondear por línea y luego sumar es
     * cómo aparece la diferencia de un bolívar que nadie sabe de dónde salió.
     */
    public function toBolivars(Money $usd): Money
    {
        return Money::fromCents(
            (int) round($usd->cents * (float) $this->value),
            'VES',
        );
    }

    /** De bolívares a dólares. Para registrar un pago recibido en bolívares. */
    public function toUsd(Money $bolivars): Money
    {
        return Money::fromCents(
            (int) round($bolivars->cents / (float) $this->value),
            'USD',
        );
    }

    public function asFloat(): float
    {
        return (float) $this->value;
    }
}
