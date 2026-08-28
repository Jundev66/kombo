<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * El plan de este negocio no da para otro producto más.
 *
 * El mensaje dice **cuántos caben y qué hacer**, no sólo que no se puede. Un
 * «límite alcanzado» a secas deja a alguien mirando la pantalla sin saber si
 * el problema tiene solución.
 */
final class PlanLimitReached extends UserError
{
    public function __construct(int $limit)
    {
        parent::__construct(
            "Tu plan llega hasta {$limit} productos. Puedes desactivar alguno que ya no vendas, ".
            'o pasar a un plan más grande.'
        );
    }

    public function field(): ?string
    {
        return 'name';
    }
}
