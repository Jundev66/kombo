<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * That order does not exist in this tenant.
 *
 * 404 and not 403: another tenant's id is invisible to RLS anyway, and saying
 * "you may not" would confirm it exists.
 */
final class OrderNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Ese pedido no existe en este negocio.');
    }
}
