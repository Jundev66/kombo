<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * The name will not do: empty, too short or too long.
 */
final class InvalidProductName extends CatalogException
{
    public function field(): ?string
    {
        return 'name';
    }
}
