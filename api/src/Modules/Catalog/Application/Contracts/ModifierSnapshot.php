<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

use Shared\Domain\ValueObjects\Money;

/**
 * Un agregado, de sólo lectura. **No es la entidad.**
 *
 * Quien cobra necesita el nombre y cuánto suma, y nada más. Recibir algo con
 * métodos que cambian el precio sería poder cambiarlo desde fuera del módulo
 * que defiende esa regla.
 */
final readonly class ModifierSnapshot
{
    public function __construct(
        public string $id,
        public string $name,
        /** Puede ser NEGATIVO: «sin queso» a veces descuenta. */
        public Money $priceDelta,
        public bool $isActive,
    ) {}
}
