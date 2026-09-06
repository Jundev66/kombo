<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * A negative price. A modifier MAY take money off; a product cannot cost less
 * than nothing.
 */
final class InvalidPrice extends CatalogException
{
    public function field(): ?string
    {
        return 'price_cents';
    }
}
