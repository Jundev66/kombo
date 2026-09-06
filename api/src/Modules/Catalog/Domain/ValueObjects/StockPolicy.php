<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidStock;

/**
 * Whether this product counts what is left, and how much.
 *
 * Most dishes do not — they are made to order. Keeping both facts in one value
 * object is what prevents the impossible "untracked but 7 left" state.
 */
final readonly class StockPolicy
{
    private function __construct(
        public bool $tracked,
        public ?int $quantity,
    ) {}

    /** Made to order: nothing to count. */
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
     * From a form or from the database. Untracked, the quantity is DISCARDED
     * rather than kept "just in case" — keeping it is how the impossible state
     * appears.
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

    /** Enough for this quantity? Untracked, there always is. */
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
