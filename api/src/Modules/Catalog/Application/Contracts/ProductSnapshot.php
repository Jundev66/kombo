<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

use Shared\Domain\ValueObjects\Money;

/**
 * What other modules need to know about a product. Not the entity: with the
 * domain `Product`, `Orders` could call `changePriceTo()` from outside.
 *
 * It carries the price because whoever charges needs it — from here, never from
 * what the browser sent.
 */
final readonly class ProductSnapshot
{
    public function __construct(
        public string $id,
        public string $name,
        public Money $price,
        public bool $isActive,
        public bool $tracksStock,
        public ?int $stockQuantity,
        public ?int $prepMinutes,
    ) {}

    /** Can this quantity be sold right now? */
    public function isSellable(int $quantity = 1): bool
    {
        if (! $this->isActive) {
            return false;
        }

        return ! $this->tracksStock || ($this->stockQuantity ?? 0) >= $quantity;
    }
}
