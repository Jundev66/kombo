<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * The stock figures do not match whether the product tracks stock at all.
 */
final class InvalidStock extends CatalogException
{
    public function field(): ?string
    {
        return 'stock_qty';
    }
}
