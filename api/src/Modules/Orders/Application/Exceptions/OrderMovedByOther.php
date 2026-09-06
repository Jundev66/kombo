<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Somebody moved the order in the meantime.
 *
 * What optimistic locking returns when `UPDATE ... where state_version = ?`
 * affects no rows. 409, not 500 — it is information, and the message asks for a
 * reload because the screen is out of date.
 */
final class OrderMovedByOther extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(
            'Alguien movió ese pedido mientras tanto. Recarga la pantalla para ver dónde está.'
        );
    }
}
