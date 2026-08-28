<?php

declare(strict_types=1);

/*
 * ¿Está abierto?
 *
 * Parece la pregunta más simple del sistema y es la que más formas tiene de
 * salir mal: turnos que cruzan la medianoche, días sin configurar, y una hora
 * que hay que mirar en el huso del negocio y no en el del servidor.
 *
 * PHP puro, sin Laravel: son minutos y comparaciones.
 */

use Modules\Portal\Domain\ValueObjects\DaySchedule;
use Modules\Portal\Domain\ValueObjects\OpeningHours;

/** Un momento concreto, en hora local del negocio. */
function momento(string $fecha): DateTimeImmutable
{
    return new DateTimeImmutable($fecha);
}

/** El mismo horario todos los días. */
function todosLosDias(string $abre, string $cierra): OpeningHours
{
    $days = [];

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $days[$weekday] = DaySchedule::open($abre, $cierra);
    }

    return OpeningHours::of($days);
}

it('dentro del horario está abierto', function (): void {
    $hours = todosLosDias('08:00', '22:00');

    expect($hours->isOpenAt(momento('2026-08-28 12:00')))->toBeTrue()
        ->and($hours->isOpenAt(momento('2026-08-28 08:00')))->toBeTrue();
});

it('fuera del horario está cerrado', function (): void {
    $hours = todosLosDias('08:00', '22:00');

    expect($hours->isOpenAt(momento('2026-08-28 07:59')))->toBeFalse()
        ->and($hours->isOpenAt(momento('2026-08-28 23:30')))->toBeFalse();
});

it('a la hora de cerrar ya está cerrado', function (): void {
    // El último pedido a las 21:59, no a las 22:00: quien cierra a las diez
    // quiere estar apagando la plancha a las diez, no empezando una arepa.
    expect(todosLosDias('08:00', '22:00')->isOpenAt(momento('2026-08-28 22:00')))->toBeFalse();
});

it('un turno que cruza la medianoche sigue abierto de madrugada', function (): void {
    // De seis de la tarde a dos de la madrugada es el horario normal de media
    // comida rápida. Con una comparación ingenua, ese negocio aparece cerrado
    // justo en sus mejores horas.
    $hours = todosLosDias('18:00', '02:00');

    expect($hours->isOpenAt(momento('2026-08-28 20:00')))->toBeTrue()
        ->and($hours->isOpenAt(momento('2026-08-29 01:30')))->toBeTrue()
        ->and($hours->isOpenAt(momento('2026-08-29 02:00')))->toBeFalse()
        ->and($hours->isOpenAt(momento('2026-08-29 10:00')))->toBeFalse();
});

it('la madrugada del día siguiente pertenece al turno del día anterior', function (): void {
    // Sólo el sábado abre de noche. La una de la madrugada del DOMINGO todavía
    // es del sábado, aunque el domingo esté marcado como cerrado.
    $days = [];

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $days[$weekday] = DaySchedule::closed();
    }

    $days[6] = DaySchedule::open('20:00', '03:00');   // sábado

    $hours = OpeningHours::of($days);

    expect($hours->isOpenAt(momento('2026-08-29 22:00')))->toBeTrue()   // sábado noche
        ->and($hours->isOpenAt(momento('2026-08-30 01:00')))->toBeTrue()   // domingo madrugada
        ->and($hours->isOpenAt(momento('2026-08-30 12:00')))->toBeFalse(); // domingo mediodía
});

it('un día sin configurar está CERRADO', function (): void {
    // El fallo seguro es no aceptar: un pedido de un día que nadie configuró
    // llega a una cocina apagada, y el cliente se queda esperando comida que
    // nadie está haciendo.
    expect(OpeningHours::never()->isOpenAt(momento('2026-08-28 12:00')))->toBeFalse();

    $days = [1 => DaySchedule::open('08:00', '22:00')];   // sólo el lunes

    expect(OpeningHours::of($days)->isOpenAt(momento('2026-08-31 12:00')))->toBeTrue()   // lunes
        ->and(OpeningHours::of($days)->isOpenAt(momento('2026-09-01 12:00')))->toBeFalse(); // martes
});

it('un día marcado como cerrado no abre aunque tenga horas', function (): void {
    expect(DaySchedule::closed()->isClosed)->toBeTrue();

    $hours = OpeningHours::of([4 => DaySchedule::closed()]);

    expect($hours->isOpenAt(momento('2026-09-03 12:00')))->toBeFalse();
});

it('media hora no es horario: sin una de las dos, cerrado', function (): void {
    // Una fila con hora de abrir y sin hora de cerrar es una fila a medio
    // guardar. Interpretarla como «abierto hasta que alguien lo arregle» deja
    // entrando pedidos toda la noche.
    expect(DaySchedule::open('08:00', null)->isClosed)->toBeTrue()
        ->and(DaySchedule::open(null, '22:00')->isClosed)->toBeTrue();
});

it('acepta las horas como las guarda PostgreSQL', function (): void {
    // `time` vuelve como «08:00:00», no como «08:00».
    $hours = OpeningHours::of([5 => DaySchedule::open('08:00:00', '22:00:00')]);

    expect($hours->isOpenAt(momento('2026-09-04 12:00')))->toBeTrue();
});
