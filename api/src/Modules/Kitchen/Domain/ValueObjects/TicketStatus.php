<?php

declare(strict_types=1);

namespace Modules\Kitchen\Domain\ValueObjects;

/**
 * Por dónde va una comanda.
 *
 *   pending ──Empezar──► preparing ──Listo──► ready ──Entregado──► served
 *
 * Es una máquina de estados PROPIA, distinta de la del pedido. La cocina tiene
 * su ciclo de vida: un pedido cancelado porque el cliente se arrepintió no
 * borra que la comida se hizo, y esas dos verdades tienen que poder convivir.
 */
enum TicketStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';

    /** Salió de la cocina. Ya no es asunto de esta pantalla. */
    case Served = 'served';

    /** El siguiente paso, o null si ya no hay. */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Served,
            self::Served => null,
        };
    }

    /** Lo que dice el botón. Lo que va a pasar, no el estado al que va. */
    public function nextLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Empezar',
            self::Preparing => 'Listo',
            self::Ready => 'Entregado',
            self::Served => null,
        };
    }

    /** El título de su columna. */
    public function columnLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por hacer',
            self::Preparing => 'En la plancha',
            self::Ready => 'Para entregar',
            self::Served => 'Servido',
        };
    }

    /** Las que se ven en la pantalla. Las servidas no llegan al cliente. */
    public function isOnScreen(): bool
    {
        return $this !== self::Served;
    }
}
