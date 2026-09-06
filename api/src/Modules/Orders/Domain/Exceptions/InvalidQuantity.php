<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

/**
 * A quantity that makes no sense: zero or negative.
 */
final class InvalidQuantity extends OrderException
{
    public function field(): ?string
    {
        return 'quantity';
    }
}
