<?php

declare(strict_types=1);

namespace Platform\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The subdomain matches no tenant.
 *
 * 404 and not 403: a 403 would confirm the tenant exists, turning the system
 * into a customer directory anyone can walk by trying subdomains.
 */
final class TenantNotFound extends NotFoundHttpException
{
    public function __construct(string $slug)
    {
        parent::__construct("No hay ningún negocio en «{$slug}».");
    }
}
