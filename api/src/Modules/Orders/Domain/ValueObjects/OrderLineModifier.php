<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

use Shared\Domain\ValueObjects\Money;

/**
 * An add-on within a line: "no onion", "extra cheese".
 *
 * Name and amount are copied, not referenced: a March order's total cannot
 * change because somebody touched the menu in September.
 */
final readonly class OrderLineModifier
{
    public function __construct(
        public ?string $modifierId,
        public string $name,
        public Money $priceDelta,
    ) {}

    /** Can take money off: "no cheese" sometimes lowers the price. */
    public function isDiscount(): bool
    {
        return $this->priceDelta->isNegative();
    }
}
