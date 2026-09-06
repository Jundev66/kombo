<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * A line, as whoever reacts to a confirmed order needs it.
 *
 * It travels inside the event and is not queried afterwards, so listeners never
 * touch the order tables. The event carries the facts.
 */
final readonly class ConfirmedOrderLine
{
    /**
     * @param  list<string>  $modifiers  Already resolved to text: "No onion".
     */
    public function __construct(
        public ?string $productId,
        public string $name,
        public int $quantity,
        public array $modifiers = [],
        public ?string $notes = null,
    ) {}
}
