<?php

declare(strict_types=1);

namespace Modules\Kitchen\Domain\ValueObjects;

/**
 * Where a ticket has got to.
 *
 *   pending ──Start──► preparing ──Ready──► ready ──Delivered──► served
 *
 * A state machine of its own, separate from the order's: an order cancelled
 * because the customer changed their mind does not erase that the food was
 * made, and both truths have to coexist.
 */
enum TicketStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';

    /** It left the kitchen. No longer this screen's business. */
    case Served = 'served';

    /**
     * No longer needs making: the sale was voided or the customer changed their
     * mind. Reached from outside, never from the kitchen screen.
     */
    case Cancelled = 'cancelled';

    /** The next step, or null if there is none. */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Served,
            self::Served, self::Cancelled => null,
        };
    }

    /** What the button says: what will happen, not the state it goes to. */
    public function nextLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Empezar',
            self::Preparing => 'Listo',
            self::Ready => 'Entregado',
            self::Served, self::Cancelled => null,
        };
    }

    /** Its column heading. */
    public function columnLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por hacer',
            self::Preparing => 'En la plancha',
            self::Ready => 'Para entregar',
            self::Served => 'Servido',
            self::Cancelled => 'Anulado',
        };
    }

    /**
     * The ones visible on the screen — neither served nor cancelled. Cancelled
     * ones vanish on the next poll, which is what stops anyone carrying on.
     */
    public function isOnScreen(): bool
    {
        return $this !== self::Served && $this !== self::Cancelled;
    }
}
