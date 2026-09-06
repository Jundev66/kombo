<?php

declare(strict_types=1);

/*
 * Is it open?
 *
 * It looks like the simplest question in the system and has the most ways to go
 * wrong: shifts that cross midnight, unconfigured days, and a clock that has to
 * be read in the tenant's timezone rather than the server's.
 *
 * Plain PHP, no Laravel: minutes and comparisons.
 */

use Modules\Portal\Domain\ValueObjects\DaySchedule;
use Modules\Portal\Domain\ValueObjects\OpeningHours;

/** A specific moment, in the tenant's local time. */
function moment(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

/** The same hours every day. */
function everyDay(string $opensAt, string $closesAt): OpeningHours
{
    $days = [];

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $days[$weekday] = DaySchedule::open($opensAt, $closesAt);
    }

    return OpeningHours::of($days);
}

it('within opening hours it is open', function (): void {
    $hours = everyDay('08:00', '22:00');

    expect($hours->isOpenAt(moment('2026-08-28 12:00')))->toBeTrue()
        ->and($hours->isOpenAt(moment('2026-08-28 08:00')))->toBeTrue();
});

it('outside opening hours it is closed', function (): void {
    $hours = everyDay('08:00', '22:00');

    expect($hours->isOpenAt(moment('2026-08-28 07:59')))->toBeFalse()
        ->and($hours->isOpenAt(moment('2026-08-28 23:30')))->toBeFalse();
});

it('at closing time it is already closed', function (): void {
    // Last order at 21:59, not 22:00: whoever closes at ten wants to be
    // switching the griddle off then, not starting an arepa.
    expect(everyDay('08:00', '22:00')->isOpenAt(moment('2026-08-28 22:00')))->toBeFalse();
});

it('a shift crossing midnight is still open in the small hours', function (): void {
    // Six in the evening to two in the morning is normal for half of fast
    // food. With a naive comparison that tenant shows closed during its best
    // hours.
    $hours = everyDay('18:00', '02:00');

    expect($hours->isOpenAt(moment('2026-08-28 20:00')))->toBeTrue()
        ->and($hours->isOpenAt(moment('2026-08-29 01:30')))->toBeTrue()
        ->and($hours->isOpenAt(moment('2026-08-29 02:00')))->toBeFalse()
        ->and($hours->isOpenAt(moment('2026-08-29 10:00')))->toBeFalse();
});

it('the small hours of the next day belong to the previous day\'s shift', function (): void {
    // Only Saturday opens at night. One in the morning on SUNDAY still belongs
    // to Saturday, even though Sunday is marked closed.
    $days = [];

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $days[$weekday] = DaySchedule::closed();
    }

    $days[6] = DaySchedule::open('20:00', '03:00');   // saturday

    $hours = OpeningHours::of($days);

    expect($hours->isOpenAt(moment('2026-08-29 22:00')))->toBeTrue()   // saturday night
        ->and($hours->isOpenAt(moment('2026-08-30 01:00')))->toBeTrue()   // early sunday
        ->and($hours->isOpenAt(moment('2026-08-30 12:00')))->toBeFalse(); // sunday midday
});

it('an unconfigured day is CLOSED', function (): void {
    // The safe failure is to refuse: an order on a day nobody configured
    // reaches an unlit kitchen, and the customer waits for food nobody is
    // making.
    expect(OpeningHours::never()->isOpenAt(moment('2026-08-28 12:00')))->toBeFalse();

    $days = [1 => DaySchedule::open('08:00', '22:00')];   // monday only

    expect(OpeningHours::of($days)->isOpenAt(moment('2026-08-31 12:00')))->toBeTrue()   // monday
        ->and(OpeningHours::of($days)->isOpenAt(moment('2026-09-01 12:00')))->toBeFalse(); // tuesday
});

it('a day marked closed does not open even with times set', function (): void {
    expect(DaySchedule::closed()->isClosed)->toBeTrue();

    $hours = OpeningHours::of([4 => DaySchedule::closed()]);

    expect($hours->isOpenAt(moment('2026-09-03 12:00')))->toBeFalse();
});

it('half an entry is not a schedule: missing either time means closed', function (): void {
    // A row with an opening time and no closing time is half saved. Reading it
    // as "open until somebody fixes it" takes orders all night.
    expect(DaySchedule::open('08:00', null)->isClosed)->toBeTrue()
        ->and(DaySchedule::open(null, '22:00')->isClosed)->toBeTrue();
});

it('accepts times in the form PostgreSQL stores them', function (): void {
    // `time` comes back as "08:00:00", not "08:00".
    $hours = OpeningHours::of([5 => DaySchedule::open('08:00:00', '22:00:00')]);

    expect($hours->isOpenAt(moment('2026-09-04 12:00')))->toBeTrue();
});
