<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

/**
 * Una cantidad que no tiene sentido: cero o negativa.
 */
final class InvalidQuantity extends OrderException
{
    public function field(): ?string
    {
        return 'quantity';
    }
}
