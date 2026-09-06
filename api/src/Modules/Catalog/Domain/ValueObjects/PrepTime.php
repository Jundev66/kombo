<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidPrepTime;

/**
 * How long a dish takes to come out. The kitchen screen's traffic light comes
 * from here; without it "running late" is a hunch.
 *
 * Optional, because a malta comes out of the fridge.
 */
final readonly class PrepTime
{
    /** Above this it is almost certainly one zero too many while typing. */
    private const MAX_MINUTES = 240;

    private function __construct(public ?int $minutes) {}

    /** Not prepared: served as it is. */
    public static function none(): self
    {
        return new self(null);
    }

    public static function ofMinutes(?int $minutes): self
    {
        if ($minutes === null) {
            return new self(null);
        }

        if ($minutes < 0) {
            throw new InvalidPrepTime('El tiempo de preparación no puede ser negativo.');
        }

        if ($minutes > self::MAX_MINUTES) {
            throw new InvalidPrepTime(
                'Ese tiempo de preparación parece un error: el máximo son '.self::MAX_MINUTES.' minutos.'
            );
        }

        return new self($minutes);
    }

    public function isKnown(): bool
    {
        return $this->minutes !== null;
    }

    public function seconds(): ?int
    {
        return $this->minutes === null ? null : $this->minutes * 60;
    }
}
