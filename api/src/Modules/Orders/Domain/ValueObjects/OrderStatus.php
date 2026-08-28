<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

/**
 * Por dónde va un pedido.
 *
 * La tabla de transiciones vive aquí y en ningún otro sitio. Es lo que impide
 * que el portal, la caja, el bot y la cocina tengan cada uno su propia idea de
 * qué puede pasar después — que es como acaban existiendo pedidos entregados
 * que vuelven a la plancha.
 *
 *   pending_payment ──► placed ──► confirmed ──► preparing ──► ready
 *   (pago móvil,        (llegó,    (el negocio   (cocina lo    (cocina
 *    esperando           esperando  lo acepta →   tomó)         terminó)
 *    comprobante)        respuesta) ENTRA A                        │
 *                                   COCINA)                        ▼
 *                                              delivered ◄── out_for_delivery
 *
 *   cualquiera no terminal ──► cancelled
 */
enum OrderStatus: string
{
    /** Pagó por transferencia y falta que alguien lo dé por bueno. */
    case PendingPayment = 'pending_payment';

    /** Llegó al negocio y espera respuesta. */
    case Placed = 'placed';

    /**
     * El negocio lo aceptó.
     *
     * **Ésta es la transición que manda el pedido a la cocina.** Es el único
     * camino: la cocina no consulta `orders` por su cuenta.
     */
    case Confirmed = 'confirmed';

    case Preparing = 'preparing';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Placed, self::Cancelled],
            self::Placed => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            // Desde «listo» se bifurca: o sale a la calle, o se lo llevan del
            // mostrador. Las dos son válidas y las decide el tipo de servicio,
            // no el estado.
            self::Ready => [self::OutForDelivery, self::Delivered, self::Cancelled],
            self::OutForDelivery => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** De aquí no se vuelve. */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** ¿Está en la cocina ahora mismo? */
    public function isInKitchen(): bool
    {
        return in_array($this, [self::Confirmed, self::Preparing], true);
    }

    /** ¿Sigue vivo, o ya se cerró de una forma u otra? */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /** Lo que ve una persona. Sin jerga y sin estados internos. */
    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Esperando el pago',
            self::Placed => 'Sin confirmar',
            self::Confirmed => 'Confirmado',
            self::Preparing => 'En la cocina',
            self::Ready => 'Listo',
            self::OutForDelivery => 'En camino',
            self::Delivered => 'Entregado',
            self::Cancelled => 'Cancelado',
        };
    }
}
