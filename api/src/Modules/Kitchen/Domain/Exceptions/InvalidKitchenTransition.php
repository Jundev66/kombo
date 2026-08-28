<?php

declare(strict_types=1);

namespace Modules\Kitchen\Domain\Exceptions;

use Modules\Kitchen\Domain\ValueObjects\TicketStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Esa comanda no puede pasar de ahí a allá.
 *
 * **409, no 422**: los datos están bien formados, lo que pasa es que la
 * comanda ya no está donde la pantalla creía. Casi siempre porque el otro
 * cocinero la movió mientras tanto.
 *
 * Por eso el mensaje dice que recargue el tablero, no que corrija algo.
 */
final class InvalidKitchenTransition extends ConflictHttpException
{
    public function __construct(TicketStatus $from, TicketStatus $to)
    {
        parent::__construct(
            "Esa comanda está en «{$from->columnLabel()}» y no puede pasar a ".
            "«{$to->columnLabel()}». Recarga el tablero."
        );
    }
}
