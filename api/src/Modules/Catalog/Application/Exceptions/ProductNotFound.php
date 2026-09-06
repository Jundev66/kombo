<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * That product does not exist in this tenant.
 *
 * 404 and not 403: an id from another tenant is invisible to RLS anyway, and
 * "you may not" would confirm it exists.
 */
final class ProductNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Ese producto no existe en este negocio.');
    }
}
