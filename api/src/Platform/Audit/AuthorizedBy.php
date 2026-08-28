<?php

declare(strict_types=1);

namespace Platform\Audit;

/**
 * Quién autorizó una acción con su PIN.
 *
 * Distinto de `Actor`: el actor es quien la INICIÓ (el cajero), y esto es
 * quien la permitió (el encargado). Las dos cosas van a la bitácora, porque la
 * conversación que esto viene a resolver es exactamente «¿quién anuló esa
 * venta?» — y la respuesta útil tiene dos nombres.
 */
final readonly class AuthorizedBy
{
    public function __construct(
        public string $userId,
        public string $userName,
    ) {}
}
