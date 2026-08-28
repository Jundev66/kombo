<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Dinero, en centavos enteros. Nunca float.
 *
 * `0.1 + 0.2 !== 0.3` no es una curiosidad académica: es un cuadre de caja que
 * no cierra al final del día y media hora de alguien buscando un céntimo que
 * nunca existió. Todos los importes del sistema viven en `int` de centavos.
 *
 * El dólar es la moneda de VALOR: precios, totales y reportes se guardan aquí.
 * El bolívar es moneda de COBRO y presentación — se calcula al momento con la
 * tasa vigente y se congela en el documento que se emitió. Eso es lo que hace
 * que un reporte de seis meses signifique algo y que una nota de entrega de
 * marzo siga diciendo lo que decía en marzo.
 *
 * @see ExchangeRate
 */
final readonly class Money
{
    private function __construct(
        public int $cents,
        public string $currency,
    ) {}

    public static function fromCents(int $cents, string $currency = 'USD'): self
    {
        return new self($cents, strtoupper($currency));
    }

    public static function zero(string $currency = 'USD'): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * Sólo para entrada de usuario y semillas. El resto del sistema habla en
     * centavos: si estás llamando a esto desde un caso de uso, probablemente
     * el importe ya venía en centavos y lo estás degradando de ida y vuelta.
     */
    public static function fromAmount(string|float|int $amount, string $currency = 'USD'): self
    {
        $normalized = is_string($amount) ? str_replace(',', '.', trim($amount)) : (string) $amount;

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("«{$amount}» no es un importe válido.");
        }

        // round() antes de (int) porque (int) trunca: 2.999999 por coma
        // flotante se convertiría en 299 en vez de 300.
        return new self((int) round(((float) $normalized) * 100), strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    /**
     * Multiplica por una cantidad que puede ser decimal (0,5 kg de queso).
     * Redondea UNA sola vez, al final.
     */
    public function times(int|float $factor): self
    {
        return new self((int) round($this->cents * $factor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    /**
     * Reparte un importe en N partes SIN perder ni ganar un céntimo.
     *
     * Repartir 100 entre 3 da [34, 33, 33], no tres veces 33,33. El resto se
     * distribuye de uno en uno entre las primeras partes. Hace falta para el
     * pago mixto y para prorratear un descuento entre las líneas de un pedido:
     * la suma de las partes tiene que dar exactamente el total, siempre.
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('No se puede repartir en menos de una parte.');
        }

        $base = intdiv($this->cents, $parts);
        $remainder = $this->cents - ($base * $parts);

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $result[] = new self($base + ($i < abs($remainder) ? ($remainder <=> 0) : 0), $this->currency);
        }

        return $result;
    }

    /**
     * Para mostrar. Nunca para calcular ni para guardar.
     */
    public function format(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $abs = abs($this->cents);

        return $sign.number_format(intdiv($abs, 100), 0, ',', '.').','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "No se pueden mezclar {$this->currency} y {$other->currency} sin una tasa de cambio."
            );
        }
    }
}
