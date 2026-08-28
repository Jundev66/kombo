<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * Un tiempo de preparación negativo, o tan largo que seguro es un error de tecleo.
 */
final class InvalidPrepTime extends CatalogException
{
    public function field(): ?string
    {
        return 'prep_minutes';
    }
}
