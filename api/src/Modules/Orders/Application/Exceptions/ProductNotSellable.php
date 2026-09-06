<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * That product cannot be sold right now.
 *
 * One message for all three reasons — gone, off the menu, sold out — because to
 * whoever is ordering they are the same thing, and telling them apart would map
 * the database for anyone trying ids.
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
