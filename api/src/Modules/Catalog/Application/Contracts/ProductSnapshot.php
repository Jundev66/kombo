<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

use Shared\Domain\ValueObjects\Money;

/**
 * Lo que otros módulos necesitan saber de un producto. **No es la entidad.**
 *
 * La diferencia importa: si `Orders` recibiera el `Product` de dominio, podría
 * llamar a `changePriceTo()` desde fuera del módulo que defiende esa regla. Un
 * objeto de sólo lectura hace que eso ni se plantee.
 *
 * Lleva el precio porque quien cobra lo necesita — y lo saca de aquí, nunca de
 * lo que mandó el navegador.
 */
final readonly class ProductSnapshot
{
    public function __construct(
        public string $id,
        public string $name,
        public Money $price,
        public bool $isActive,
        public bool $tracksStock,
        public ?int $stockQuantity,
        public ?int $prepMinutes,
    ) {}

    /** ¿Se le puede vender esta cantidad a alguien ahora mismo? */
    public function isSellable(int $quantity = 1): bool
    {
        if (! $this->isActive) {
            return false;
        }

        return ! $this->tracksStock || ($this->stockQuantity ?? 0) >= $quantity;
    }
}
