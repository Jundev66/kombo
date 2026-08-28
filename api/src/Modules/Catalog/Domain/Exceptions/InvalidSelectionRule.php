<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * La regla de un grupo de modificadores es incoherente (mínimo mayor que máximo, o un máximo de cero).
 */
final class InvalidSelectionRule extends CatalogException
{
    public function field(): ?string
    {
        return 'max_choices';
    }
}
