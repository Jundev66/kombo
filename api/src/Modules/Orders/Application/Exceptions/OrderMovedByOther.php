<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Alguien movió el pedido mientras tanto.
 *
 * Es lo que devuelve el bloqueo optimista cuando el `UPDATE ... where
 * state_version = ?` no afecta ninguna fila. Pasa de verdad y a diario: la
 * caja y la pantalla de cocina miran el mismo pedido, y dos personas pulsan
 * casi a la vez.
 *
 * **409, no 500.** No es un fallo: es información. Y el mensaje pide recargar,
 * porque lo que hay en la pantalla ya no es lo que hay en el sistema.
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
