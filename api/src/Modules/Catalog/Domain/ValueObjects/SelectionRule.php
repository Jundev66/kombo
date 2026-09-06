<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidSelectionRule;

/**
 * How many options can be picked from a modifier group.
 *
 *   (0, N)  optional extras          "anything else?"
 *   (1, 1)  pick one, required       "how would you like the meat?"
 *   (0, 1)  optional, exclusive      "any sauce?"
 *
 * Here rather than in a form validation: the till, the portal and the bot have
 * to apply the same rule, and three validations are three chances to go stale.
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

    /** "Pick one" — the most common in food. */
    public static function exactlyOne(): self
    {
        return new self(1, 1);
    }

    /** Optional extras, as many as they like. */
    public static function anyNumber(int $max = 99): self
    {
        return new self(0, $max);
    }

    public function isRequired(): bool
    {
        return $this->min > 0;
    }

    /**
     * Does a selection satisfy the rule? The portal uses it to block progress,
     * the server to not trust the portal.
     */
    public function accepts(int $chosen): bool
    {
        return $chosen >= $this->min && $chosen <= $this->max;
    }

    /** What to tell whoever is ordering when it does not. */
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
