<?php

declare(strict_types=1);

namespace Modules\Portal\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * Is it open right now? Two decisions that look like details and are not.
 *
 * A shift can cross midnight — "six in the evening to two in the morning" is
 * normal for fast food, and a naive `close > open` shows the tenant closed
 * during its best hours.
 *
 * An unconfigured day is CLOSED. The safe failure: an order on a day nobody
 * configured reaches a kitchen with the lights off.
 */
final readonly class OpeningHours
{
    /**
     * @param  array<int, DaySchedule>  $days  indexed by weekday, 0 = Sunday
     */
    private function __construct(private array $days) {}

    /**
     * @param  array<int, DaySchedule>  $days
     */
    public static function of(array $days): self
    {
        return new self($days);
    }

    /** Nobody configured anything: closed always. */
    public static function never(): self
    {
        return new self([]);
    }

    /**
     * @param  DateTimeImmutable  $at  the tenant's LOCAL time, already converted.
     */
    public function isOpenAt(DateTimeImmutable $at): bool
    {
        $weekday = (int) $at->format('w');
        $minutes = ((int) $at->format('G')) * 60 + (int) $at->format('i');

        if ($this->openIn($weekday, $minutes)) {
            return true;
        }

        // Two in the morning on Tuesday still belongs to MONDAY's shift, or the
        // tenant closes itself at midnight.
        return $this->openIn(($weekday + 6) % 7, $minutes + 1440);
    }

    private function openIn(int $weekday, int $minutes): bool
    {
        $day = $this->days[$weekday] ?? null;

        if ($day === null || $day->isClosed || $day->opensMinutes === null || $day->closesMinutes === null) {
            return false;
        }

        $opens = $day->opensMinutes;
        $closes = $day->closesMinutes;

        // Closes before it opens: the shift crosses midnight.
        if ($closes <= $opens) {
            $closes += 1440;
        }

        return $minutes >= $opens && $minutes < $closes;
    }
}
