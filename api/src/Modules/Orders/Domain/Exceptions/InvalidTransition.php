<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The order cannot go from here to there.
 *
 * 409, not 422: the data is well formed, the order is simply no longer where
 * whoever pressed the button thought. Hence the message says to reload.
 */
final class InvalidTransition extends ConflictHttpException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            "Ese pedido está en «{$from->label()}» y no puede pasar a «{$to->label()}». ".
            'Puede que alguien lo haya movido: recarga la pantalla.'
        );
    }
}
