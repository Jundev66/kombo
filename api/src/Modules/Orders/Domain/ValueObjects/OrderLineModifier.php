<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

use Shared\Domain\ValueObjects\Money;

/**
 * Un agregado dentro de una línea del pedido: «sin cebolla», «extra queso».
 *
 * El nombre y el importe van **copiados, no referenciados**. Si mañana se
 * renombra el modificador o se borra de la carta, la comanda de hoy tiene que
 * seguir diciendo lo que se pidió — y el total de un pedido de marzo no puede
 * cambiar porque alguien tocó la carta en septiembre.
 */
final readonly class OrderLineModifier
{
    public function __construct(
        public ?string $modifierId,
        public string $name,
        public Money $priceDelta,
    ) {}

    /** Puede DESCONTAR: «sin queso» a veces baja el precio. */
    public function isDiscount(): bool
    {
        return $this->priceDelta->isNegative();
    }
}
