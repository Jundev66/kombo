<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * El pedido no puede pasar de ahí a allá.
 *
 * **409, no 422**, y la diferencia importa en la pantalla: los datos que
 * llegaron están bien formados; lo que pasa es que el pedido **ya no está
 * donde quien pulsó el botón creía**. Casi siempre porque otra persona lo
 * movió mientras tanto.
 *
 * Por eso el mensaje dice que recargue, no que corrija algo.
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
