<?php

declare(strict_types=1);

namespace Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * The current tenant was asked for and there is none.
 *
 * A programming error, not a business one: code that assumes context ran
 * outside a tenant request. It throws rather than returning null on purpose —
 * a `?Tenant` would let some caller decide to "carry on unfiltered".
 */
final class TenantNotResolved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'No hay un negocio en contexto. Si esto corre en una cola o en un '.
            'comando, fija el negocio explícitamente antes de tocar datos.'
        );
    }
}
