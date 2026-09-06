<?php

declare(strict_types=1);

namespace Modules\Portal\Domain\ValueObjects;

/**
 * A day of the week with its hours, in minutes from midnight.
 *
 * Minutes rather than `H:i`, or "09:00" is greater than "10:00" the day
 * somebody saves "9:00".
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

    /** `null` in either time means closed: there is no half-open. */
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
