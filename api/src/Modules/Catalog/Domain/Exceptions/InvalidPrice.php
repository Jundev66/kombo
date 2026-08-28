<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

/**
 * Un precio negativo. Un modificador SÍ puede descontar; un producto no puede costar menos que nada.
 */
final class InvalidPrice extends CatalogException
{
    public function field(): ?string
    {
        return 'price_cents';
    }
}
