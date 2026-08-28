<?php

declare(strict_types=1);

namespace Modules\Portal\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * ¿Está abierto ahora mismo?
 *
 * Dos decisiones que parecen detalles y no lo son:
 *
 * **Un turno puede cruzar la medianoche.** «De 6 de la tarde a 2 de la
 * madrugada» es el horario normal de media comida rápida, y con una
 * comparación ingenua (`cierra > abre`) ese negocio aparece cerrado justo en
 * sus mejores horas.
 *
 * **Un día sin configurar está CERRADO.** El fallo seguro es no aceptar: un
 * pedido que entra un día que nadie configuró llega a una cocina apagada, y el
 * cliente se queda esperando comida que nadie está haciendo.
 */
final readonly class OpeningHours
{
    /**
     * @param  array<int, DaySchedule>  $days  indexado por día de la semana, 0 = domingo
     */
    private function __construct(private array $days) {}

    /**
     * @param  array<int, DaySchedule>  $days
     */
    public static function of(array $days): self
    {
        return new self($days);
    }

    /** Nadie configuró nada: cerrado siempre. */
    public static function never(): self
    {
        return new self([]);
    }

    /**
     * @param  DateTimeImmutable  $at  la hora LOCAL del negocio, ya convertida.
     */
    public function isOpenAt(DateTimeImmutable $at): bool
    {
        $weekday = (int) $at->format('w');
        $minutes = ((int) $at->format('G')) * 60 + (int) $at->format('i');

        if ($this->openIn($weekday, $minutes)) {
            return true;
        }

        // Las dos de la madrugada del martes todavía pertenecen al turno del
        // LUNES. Sin esto, el negocio cierra solo a medianoche.
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

        // Cierra antes de abrir: el turno cruza la medianoche.
        if ($closes <= $opens) {
            $closes += 1440;
        }

        return $minutes >= $opens && $minutes < $closes;
    }
}
