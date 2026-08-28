<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidSelectionRule;

/**
 * Cuántas opciones se pueden elegir de un grupo de modificadores.
 *
 * Un grupo es una PREGUNTA, y esta regla dice de qué tipo:
 *
 *   (0, N)  extras opcionales        «¿algo más?»
 *   (1, 1)  elegir uno, obligatorio  «¿término de la carne?»
 *   (0, 1)  opcional excluyente      «¿alguna salsa?»
 *   (2, 3)  «elige de dos a tres acompañantes»
 *
 * Está aquí y no en una validación del formulario porque la caja, el portal y
 * el bot tienen que aplicar exactamente la misma regla. Tres validaciones
 * distintas son tres oportunidades de que una se quede vieja.
 */
final readonly class SelectionRule
{
    private function __construct(
        public int $min,
        public int $max,
    ) {}

    public static function of(int $min, int $max): self
    {
        if ($min < 0) {
            throw new InvalidSelectionRule('El mínimo de opciones no puede ser negativo.');
        }

        if ($max < 1) {
            throw new InvalidSelectionRule('Un grupo tiene que dejar elegir al menos una opción.');
        }

        if ($min > $max) {
            throw new InvalidSelectionRule('El mínimo de opciones no puede ser mayor que el máximo.');
        }

        return new self($min, $max);
    }

    /** «Elige uno» — lo más común en comida. */
    public static function exactlyOne(): self
    {
        return new self(1, 1);
    }

    /** Extras opcionales, tantos como quiera. */
    public static function anyNumber(int $max = 99): self
    {
        return new self(0, $max);
    }

    public function isRequired(): bool
    {
        return $this->min > 0;
    }

    /**
     * ¿Una selección concreta cumple la regla?
     *
     * Lo usan el portal para no dejar seguir, y el servidor para no fiarse del
     * portal.
     */
    public function accepts(int $chosen): bool
    {
        return $chosen >= $this->min && $chosen <= $this->max;
    }

    /** Qué decirle a quien está pidiendo cuando no cumple. */
    public function explain(): string
    {
        if ($this->min === $this->max) {
            return $this->min === 1
                ? 'Elige una opción.'
                : "Elige exactamente {$this->min} opciones.";
        }

        if ($this->min === 0) {
            return "Puedes elegir hasta {$this->max}.";
        }

        return "Elige entre {$this->min} y {$this->max} opciones.";
    }
}
