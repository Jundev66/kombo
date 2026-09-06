<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * An order was cancelled, so whoever is doing something with it stops — the
 * kitchen above all, or the cook finishes a dish nobody will collect.
 *
 * Like `OrderConfirmed`, it travels with the facts the listener needs.
 */
final readonly class OrderCancelled
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $reason,
    ) {}
}
