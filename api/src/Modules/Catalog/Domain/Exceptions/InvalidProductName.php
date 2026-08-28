<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * El nombre no sirve: vacío, demasiado corto o demasiado largo.
 */
final class InvalidProductName extends CatalogException
{
    public function field(): ?string
    {
        return 'name';
    }
}
