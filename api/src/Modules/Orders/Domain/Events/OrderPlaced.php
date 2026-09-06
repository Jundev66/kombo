<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * An order came in. The thinnest of this module's three notices: who ordered,
 * and for how much.
 *
 *   `OrderPlaced`     who, and for how much.
 *   `OrderConfirmed`  what to make, with everything.
 *   `OrderAdvanced`   where it went.
 */
final readonly class OrderPlaced
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $channel,
        public int $totalCents,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
    ) {}
}
