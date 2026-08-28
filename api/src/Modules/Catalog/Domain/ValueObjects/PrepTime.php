<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidPrepTime;

/**
 * Cuánto tarda en salir un plato.
 *
 * De aquí sale el **semáforo de la pantalla de cocina**: sin esto, «va tarde»
 * sería una corazonada del cocinero y no un dato. Es opcional porque no todo
 * lo que se vende se prepara —una malta se saca de la nevera— y obligar a
 * poner un tiempo a las bebidas sería ruido en el formulario.
 */
final readonly class PrepTime
{
    /** Por encima de esto casi seguro es un cero de más al teclear. */
    private const MAX_MINUTES = 240;

    private function __construct(public ?int $minutes) {}

    /** No se prepara: se sirve y ya. */
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
