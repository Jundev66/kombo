<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\ValueObjects;

/**
 * Where an order has got to. The transition table lives here and nowhere else.
 *
 *   pending_payment ──► placed ──► confirmed ──► preparing ──► ready
 *                                  (goes to the kitchen)          │
 *                                                                 ▼
 *                                            delivered ◄── out_for_delivery
 *
 *   any non-terminal ──► cancelled
 *
 * One table, or the portal, the till, the bot and the kitchen each grow their
 * own idea of what can happen next — which is how delivered orders end up back
 * on the griddle.
 */
enum OrderStatus: string
{
    /** Paid by transfer; somebody still has to take it as good. */
    case PendingPayment = 'pending_payment';

    /** It reached the tenant and is awaiting an answer. */
    case Placed = 'placed';

    /**
     * The tenant accepted it. This is the transition that sends the order to
     * the kitchen, and it is the only path.
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
            // From "ready" it forks: out on the road, or collected at the counter.
            // The service type decides, not the state.
            self::Ready => [self::OutForDelivery, self::Delivered, self::Cancelled],
            self::OutForDelivery => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** There is no coming back from here. */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Is it in the kitchen right now? */
    public function isInKitchen(): bool
    {
        return in_array($this, [self::Confirmed, self::Preparing], true);
    }

    /** Still live, or closed one way or another? */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /** What a person sees. No jargon and no internal states. */
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
