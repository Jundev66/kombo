<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * The tenant accepted the order. This is the trigger that sends it to the
 * kitchen; the ticket board never queries `orders` itself.
 *
 * An event rather than a direct call because `Orders` cannot know `Kitchen`,
 * and it carries everything needed to react — a listener that had to query
 * again would bring the coupling back in through the back door.
 */
final readonly class OrderConfirmed
{
    /**
     * @param  list<ConfirmedOrderLine>  $lines
     */
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $serviceType,
        public array $lines,
        public ?int $prepMinutes = null,
        public ?string $notes = null,
        public ?string $confirmedByName = null,
    ) {}
}
