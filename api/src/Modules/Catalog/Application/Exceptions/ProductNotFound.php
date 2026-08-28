<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ese producto no existe **en este negocio**.
 *
 * 404 y no 403: si el identificador es de otro negocio, RLS hace que la
 * consulta ni lo encuentre, así que decir «no puedes» sería confirmar que
 * existe. Aquí las dos situaciones —no existe, o es de otro— dan exactamente
 * la misma respuesta, que es lo correcto.
 */
final class ProductNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Ese producto no existe en este negocio.');
    }
}
