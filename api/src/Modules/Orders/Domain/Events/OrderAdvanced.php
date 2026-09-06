<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * An order changed state. The THIN notice: which order, and where it went.
 *
 * It coexists with the fat `OrderConfirmed` because loading an order's lines
 * every time somebody taps "Delivered" would be work done for nobody to read.
 */
final readonly class OrderAdvanced
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $status,
        public string $previousStatus,
    ) {}
}
