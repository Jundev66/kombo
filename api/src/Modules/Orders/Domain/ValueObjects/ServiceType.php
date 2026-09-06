<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

/**
 * How it is handed to the customer.
 *
 * The kitchen reads it — takeaway is packed differently — and it decides
 * whether "ready" goes out on the road or waits at the counter.
 */
enum ServiceType: string
{
    case Takeaway = 'takeaway';
    case DineIn = 'dine_in';
    case Delivery = 'delivery';

    /** Does somebody have to go out and take it? */
    public function needsCourier(): bool
    {
        return $this === self::Delivery;
    }

    public function label(): string
    {
        return match ($this) {
            self::Takeaway => 'Para llevar',
            self::DineIn => 'Para comer aquí',
            self::Delivery => 'Delivery',
        };
    }
}
