<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

use Shared\Domain\ValueObjects\Money;

/**
 * An add-on, read-only. Not the entity — a type with methods that change the
 * price could be used from outside the module that defends that rule.
 */
final readonly class ModifierSnapshot
{
    public function __construct(
        public string $id,
        public string $name,
        /** Can be NEGATIVE: "no cheese" sometimes takes money off. */
        public Money $priceDelta,
        public bool $isActive,
    ) {}
}
