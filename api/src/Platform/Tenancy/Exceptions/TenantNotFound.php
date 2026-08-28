<?php

declare(strict_types=1);

namespace Platform\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El subdominio no corresponde a ningún negocio.
 *
 * **404 y no 403**, deliberadamente. Un 403 confirmaría que ese negocio existe,
 * y eso convierte el sistema en un directorio de clientes que cualquiera puede
 * recorrer probando subdominios.
 */
final class TenantNotFound extends NotFoundHttpException
{
    public function __construct(string $slug)
    {
        parent::__construct("No hay ningún negocio en «{$slug}».");
    }
}
