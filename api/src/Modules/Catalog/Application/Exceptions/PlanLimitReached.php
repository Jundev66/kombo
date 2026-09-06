<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * This tenant's plan does not stretch to another product. The message says how
 * many fit and what to do, not just that it cannot be done.
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
