<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

/**
 * An order with no lines: nothing to charge for and nothing to cook.
 */
final class EmptyOrder extends OrderException
{
    public function field(): ?string
    {
        return 'items';
    }
}
