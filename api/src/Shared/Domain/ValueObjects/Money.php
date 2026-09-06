<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Money, in whole cents. Never a float.
 *
 * `0.1 + 0.2 !== 0.3` is not an academic curiosity: it is a cash count that
 * does not balance and half an hour hunting a cent that never existed.
 *
 * The dollar is the unit of value; the bolívar is computed at the moment and
 * frozen into the document issued.
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
     * For user input and seeds only. The rest of the system speaks in cents: if
     * you are calling this from a use case, the amount probably already was.
     */
    public static function fromAmount(string|float|int $amount, string $currency = 'USD'): self
    {
        $normalized = is_string($amount) ? str_replace(',', '.', trim($amount)) : (string) $amount;

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("«{$amount}» no es un importe válido.");
        }

        // round() before (int) because (int) truncates: 2.999999 from floating
        // point would become 299 instead of 300.
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
     * Multiplies by a possibly fractional quantity (0.5 kg of cheese), rounding
     * ONCE at the end.
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
     * Splits an amount into N parts without losing or gaining a cent: 100 three
     * ways is [34, 33, 33], not three times 33.33. Needed for mixed payment and
     * for prorating a discount across lines.
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
     * For display. Never for computing and never for storing.
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
