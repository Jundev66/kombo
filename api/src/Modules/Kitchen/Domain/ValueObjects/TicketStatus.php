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

    /**
     * Ya no hay que hacerla: se anuló la venta o el cliente se arrepintió.
     *
     * No se llega aquí desde la pantalla de cocina —el cocinero no cancela
     * nada—, sino desde fuera, cuando se cancela el pedido.
     */
    case Cancelled = 'cancelled';

    /** El siguiente paso, o null si ya no hay. */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Served,
            self::Served, self::Cancelled => null,
        };
    }

    /** Lo que dice el botón. Lo que va a pasar, no el estado al que va. */
    public function nextLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Empezar',
            self::Preparing => 'Listo',
            self::Ready => 'Entregado',
            self::Served, self::Cancelled => null,
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
            self::Cancelled => 'Anulado',
        };
    }

    /**
     * Las que se ven en la pantalla.
     *
     * Ni las servidas ni las anuladas: unas ya salieron y las otras no hay que
     * hacerlas. Las anuladas desaparecen del tablero en el siguiente sondeo,
     * que es justo lo que hace falta para que nadie siga con ellas.
     */
    public function isOnScreen(): bool
    {
        return $this !== self::Served && $this !== self::Cancelled;
    }
}
