<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

use Modules\Orders\Domain\Exceptions\InvalidQuantity;
use Shared\Domain\ValueObjects\Money;

/**
 * One line of the order: two reinas pepiadas, one without onion.
 *
 * Name and unit price are COPIED from the catalog at order time. A ticket from
 * six months ago must say what it said when it was printed, even if the product
 * has since been renamed, repriced or deleted.
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
     * What the add-ons on ONE unit come to: two burgers with extra cheese carry
     * the extra twice.
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
     * The line total: `(price + add-ons) × quantity`, and NOT
     * `price × quantity + add-ons`, which charges for one extra and gives the
     * other away.
     */
    public function total(): Money
    {
        return $this->unitPrice->plus($this->modifiersTotal())->times($this->quantity);
    }

    /**
     * The add-ons as text for the ticket and the document: the kitchen reads
     * "NO ONION · EXTRA CHEESE", not ids to look up.
     */
    public function modifiersText(): string
    {
        return implode(' · ', array_map(
            static fn (OrderLineModifier $m): string => $m->name,
            $this->modifiers,
        ));
    }
}
