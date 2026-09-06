<?php

declare(strict_types=1);

namespace Modules\Kitchen\Domain\Exceptions;

use Modules\Kitchen\Domain\ValueObjects\TicketStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * That ticket cannot go from here to there.
 *
 * 409, not 422: the data is well formed, the ticket is simply no longer where
 * the screen thought — usually because the other cook moved it. Hence the
 * message says to reload the board, not to correct something.
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
