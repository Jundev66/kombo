<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidStock;

/**
 * Si este producto lleva la cuenta de lo que queda, y cuánto queda.
 *
 * La mayoría de los platos NO la llevan: se hacen al momento y no tiene sentido
 * preguntarse cuántas arepas quedan. Se activa para lo contado —las diez tortas
 * del día, las cervezas de la nevera— y sólo entonces la cantidad significa
 * algo.
 *
 * Que las dos cosas vivan juntas en un value object es lo que impide el estado
 * imposible «no lleva cuenta pero quedan 7», que es el que hace que una
 * pantalla muestre existencias de algo que nunca se contó.
 */
final readonly class StockPolicy
{
    private function __construct(
        public bool $tracked,
        public ?int $quantity,
    ) {}

    /** Se hace al momento: no hay nada que contar. */
    public static function untracked(): self
    {
        return new self(false, null);
    }

    public static function tracked(int $quantity): self
    {
        if ($quantity < 0) {
            throw new InvalidStock('No pueden quedar menos de cero.');
        }

        return new self(true, $quantity);
    }

    /**
     * Desde lo que llega de un formulario o de la base.
     *
     * Si no lleva cuenta, la cantidad se DESCARTA en vez de guardarse
     * «por si acaso»: guardarla es justo cómo aparece el estado imposible.
     */
    public static function from(bool $tracked, ?int $quantity): self
    {
        if (! $tracked) {
            return self::untracked();
        }

        if ($quantity === null) {
            throw new InvalidStock('Si el producto lleva la cuenta, hay que decir cuánto queda.');
        }

        return self::tracked($quantity);
    }

    /** ¿Alcanza para esta cantidad? Sin cuenta, siempre alcanza. */
    public function allows(int $quantity): bool
    {
        return ! $this->tracked || ($this->quantity ?? 0) >= $quantity;
    }

    public function decrease(int $quantity): self
    {
        if (! $this->tracked) {
            return $this;
        }

        return self::tracked(max(0, ($this->quantity ?? 0) - $quantity));
    }

    public function increase(int $quantity): self
    {
        if (! $this->tracked) {
            return $this;
        }

        return self::tracked(($this->quantity ?? 0) + $quantity);
    }

    public function isSoldOut(): bool
    {
        return $this->tracked && ($this->quantity ?? 0) <= 0;
    }
}
