<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * A negative prep time, or one so long it is certainly a typo.
 */
final class InvalidPrepTime extends CatalogException
{
    public function field(): ?string
    {
        return 'prep_minutes';
    }
}
