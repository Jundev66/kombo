<?php

declare(strict_types=1);

namespace Modules\Portal\Domain\ValueObjects;

/**
 * Un día de la semana con su horario, en minutos desde medianoche.
 *
 * En minutos y no en `H:i` porque comparar horas como texto es el camino corto
 * a que «09:00» sea mayor que «10:00» el día que alguien guarde «9:00».
 */
final readonly class DaySchedule
{
    private function __construct(
        public bool $isClosed,
        public ?int $opensMinutes,
        public ?int $closesMinutes,
    ) {}

    public static function closed(): self
    {
        return new self(true, null, null);
    }

    /** `null` en cualquiera de las dos horas es cerrado: no hay medio horario. */
    public static function open(?string $opensAt, ?string $closesAt): self
    {
        $opens = self::toMinutes($opensAt);
        $closes = self::toMinutes($closesAt);

        if ($opens === null || $closes === null) {
            return self::closed();
        }

        return new self(false, $opens, $closes);
    }

    private static function toMinutes(?string $time): ?int
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $parts)) {
            return null;
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }
}
