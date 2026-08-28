<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * Ese producto no se puede vender ahora mismo.
 *
 * Un solo mensaje para las tres razones —no existe, lo sacaron de la carta, se
 * acabó— porque para quien está pidiendo son la misma cosa. Distinguirlas sólo
 * serviría para que alguien deduzca qué hay en la base probando
 * identificadores.
 */
final class ProductNotSellable extends UserError
{
    public function __construct(?string $name = null)
    {
        parent::__construct(
            $name === null
                ? 'Uno de los productos ya no está disponible.'
                : "«{$name}» ya no está disponible."
        );
    }

    public function field(): ?string
    {
        return 'items';
    }
}
