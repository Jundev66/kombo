<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * A modifier group's rule is incoherent (minimum above maximum, or a maximum of
 * zero).
 */
final class InvalidSelectionRule extends CatalogException
{
    public function field(): ?string
    {
        return 'max_choices';
    }
}
