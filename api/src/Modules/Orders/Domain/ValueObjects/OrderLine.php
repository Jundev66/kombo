<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

use Modules\Orders\Domain\Exceptions\InvalidQuantity;
use Shared\Domain\ValueObjects\Money;

/**
 * Una línea del pedido: dos reinas pepiadas, una sin cebolla.
 *
 * El nombre y el precio unitario van **copiados** del catálogo al momento de
 * pedir. Es deliberado y es la regla más importante de aquí: un ticket de hace
 * seis meses debe decir lo que decía cuando se imprimió, aunque el producto se
 * haya renombrado, encarecido o borrado.
 */
final readonly class OrderLine
{
    /**
     * @param  list<OrderLineModifier>  $modifiers
     */
    public function __construct(
        public string $productId,
        public string $productName,
        public Money $unitPrice,
        public int $quantity,
        public array $modifiers = [],
        public ?string $notes = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidQuantity('Una línea del pedido tiene que llevar al menos uno.');
        }
    }

    /**
     * Lo que suman los agregados de UNA unidad.
     *
     * Dos hamburguesas con extra queso llevan el extra dos veces: el queso va
     * en cada una, no en el pedido.
     */
    public function modifiersTotal(): Money
    {
        $total = Money::zero($this->unitPrice->currency);

        foreach ($this->modifiers as $modifier) {
            $total = $total->plus($modifier->priceDelta);
        }

        return $total;
    }

    /**
     * El total de la línea.
     *
     * `(precio + agregados) × cantidad`, y NO `precio × cantidad + agregados`.
     * La diferencia se ve en cuanto alguien pide dos de algo con extra: la
     * segunda fórmula cobra un solo extra y regala el otro.
     */
    public function total(): Money
    {
        return $this->unitPrice->plus($this->modifiersTotal())->times($this->quantity);
    }

    /**
     * Los agregados en texto, para la comanda y para el documento.
     *
     * Ya resueltos: la cocina lee «SIN CEBOLLA · EXTRA QUESO», no una lista de
     * identificadores que habría que ir a buscar.
     */
    public function modifiersText(): string
    {
        return implode(' · ', array_map(
            static fn (OrderLineModifier $m): string => $m->name,
            $this->modifiers,
        ));
    }
}
