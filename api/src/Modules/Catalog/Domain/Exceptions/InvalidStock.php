<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * Las existencias no cuadran con si el producto las lleva o no.
 */
final class InvalidStock extends CatalogException
{
    public function field(): ?string
    {
        return 'stock_qty';
    }
}
