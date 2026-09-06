<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * The rate of the day: how many bolívares a dollar is worth.
 *
 * The dollar is the unit of VALUE — prices, totals and reports live in it — and
 * the bolívar is the unit of PAYMENT, computed at the moment and frozen into
 * the document issued. Without that asymmetry, comparing March with September
 * would compare two different currencies with the same name.
 */
final readonly class ExchangeRate
{
    /**
     * Six decimals. With fewer, a $500 sale can drift several bolívares —
     * enough to stop a cash count balancing.
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
            // A rate of zero would turn every price into zero, and the first to find
            // out would be the customer.
            throw new InvalidArgumentException('La tasa tiene que ser mayor que cero.');
        }

        return new self(number_format((float) $normalized, self::SCALE, '.', ''));
    }

    /**
     * Dollars to bolívares, rounding ONCE at the end. Rounding per line and
     * then summing is how the unaccountable one-bolívar difference appears.
     */
    public function toBolivars(Money $usd): Money
    {
        return Money::fromCents(
            (int) round($usd->cents * (float) $this->value),
            'VES',
        );
    }

    /** Bolívares to dollars. For recording a payment received in bolívares. */
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
