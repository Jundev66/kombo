<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

/**
 * Cómo se le entrega al cliente.
 *
 * Lo lee la cocina —«para llevar» se empaca distinto que «para comer aquí»— y
 * decide si desde «listo» el pedido sale a la calle o se queda esperando en el
 * mostrador.
 */
enum ServiceType: string
{
    case Takeaway = 'takeaway';
    case DineIn = 'dine_in';
    case Delivery = 'delivery';

    /** ¿Alguien tiene que salir a llevarlo? */
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
