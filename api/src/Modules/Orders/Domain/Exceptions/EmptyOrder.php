<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

/**
 * Un pedido sin líneas. No es un error de tecleo: es que no hay nada que cobrar ni que cocinar.
 */
final class EmptyOrder extends OrderException
{
    public function field(): ?string
    {
        return 'items';
    }
}
