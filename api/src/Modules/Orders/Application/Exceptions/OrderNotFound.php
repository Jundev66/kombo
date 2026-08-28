<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ese pedido no existe **en este negocio**.
 *
 * 404 y no 403: si el identificador es de otro negocio, RLS hace que la
 * consulta ni lo encuentre. Las dos situaciones dan la misma respuesta, que es
 * lo correcto — decir «no puedes» confirmaría que existe.
 */
final class OrderNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Ese pedido no existe en este negocio.');
    }
}
