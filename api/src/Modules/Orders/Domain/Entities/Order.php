<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Entities;

use DateTimeImmutable;
use Modules\Orders\Domain\Exceptions\EmptyOrder;
use Modules\Orders\Domain\Exceptions\InvalidTransition;
use Modules\Orders\Domain\ValueObjects\OrderLine;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Shared\Domain\ValueObjects\Money;

/**
 * Un pedido.
 *
 * PHP puro. Las reglas de abajo —qué transición vale, cuánto suma, cuándo se
 * puede cancelar— tienen que valer igual llamadas desde el portal, desde el
 * bot, desde la caja y desde la pantalla de cocina. Metidas en un controlador,
 * valen sólo donde alguien se acordó de llamarlas.
 */
final class Order
{
    /**
     * @param  list<OrderLine>  $lines
     */
    private function __construct(
        public readonly string $id,
        private OrderStatus $status,
        private readonly ServiceType $serviceType,
        private array $lines,
        private Money $deliveryFee,
        private ?string $notes,
        private ?string $cancellationReason,
        private array $timestamps,
    ) {}

    /**
     * @param  list<OrderLine>  $lines
     */
    public static function place(
        string $id,
        ServiceType $serviceType,
        array $lines,
        ?Money $deliveryFee = null,
        ?string $notes = null,
        bool $awaitingPayment = false,
        ?DateTimeImmutable $now = null,
    ): self {
        if ($lines === []) {
            throw new EmptyOrder('Un pedido sin nada que cobrar no es un pedido.');
        }

        $now ??= new DateTimeImmutable;

        return new self(
            id: $id,
            // Con pago móvil el pedido nace esperando el comprobante; en
            // efectivo o desde la caja, nace ya recibido. Es la única
            // diferencia entre las dos formas de entrar.
            status: $awaitingPayment ? OrderStatus::PendingPayment : OrderStatus::Placed,
            serviceType: $serviceType,
            lines: $lines,
            deliveryFee: $deliveryFee ?? Money::zero(),
            notes: $notes,
            cancellationReason: null,
            timestamps: ['placed_at' => $now],
        );
    }

    /**
     * @param  list<OrderLine>  $lines
     * @param  array<string, DateTimeImmutable|null>  $timestamps
     */
    public static function rehydrate(
        string $id,
        OrderStatus $status,
        ServiceType $serviceType,
        array $lines,
        Money $deliveryFee,
        ?string $notes,
        ?string $cancellationReason,
        array $timestamps,
    ): self {
        return new self($id, $status, $serviceType, $lines, $deliveryFee, $notes, $cancellationReason, $timestamps);
    }

    /**
     * Mover el pedido al siguiente estado.
     *
     * Sella la hora del paso, que es de donde salen después «cuánto tardamos
     * en confirmar» y «cuánto tarda la cocina».
     */
    public function moveTo(OrderStatus $next, ?DateTimeImmutable $now = null): void
    {
        // Repetir el paso en el que ya está NO es error: dos personas tocando
        // «Confirmar» a la vez no pueden hacer saltar un mensaje rojo en mitad
        // del servicio. Es lo mismo que hace la pantalla de cocina.
        if ($this->status === $next) {
            return;
        }

        if (! $this->status->canMoveTo($next)) {
            throw new InvalidTransition($this->status, $next);
        }

        $this->status = $next;
        $this->timestamps[self::stampFor($next)] = $now ?? new DateTimeImmutable;
    }

    /**
     * Cancelar.
     *
     * Es el ÚNICO camino que se salta la tabla de transiciones: desde
     * cualquier punto vivo se puede cancelar, porque en la vida real un cliente
     * se arrepiente en cualquier momento. Lo que no se salta es que un pedido
     * terminal no revive — entregado es entregado.
     */
    public function cancel(string $reason, ?DateTimeImmutable $now = null): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidTransition($this->status, OrderStatus::Cancelled);
        }

        $this->status = OrderStatus::Cancelled;
        $this->cancellationReason = $reason;
        $this->timestamps['cancelled_at'] = $now ?? new DateTimeImmutable;
    }

    /** Lo que suman las líneas, sin el reparto. */
    public function subtotal(): Money
    {
        $subtotal = Money::zero();

        foreach ($this->lines as $line) {
            $subtotal = $subtotal->plus($line->total());
        }

        return $subtotal;
    }

    public function total(): Money
    {
        return $this->subtotal()->plus($this->deliveryFee);
    }

    /**
     * Cuánto debería tardar la cocina, según lo que lleva.
     *
     * Se toma el MÁXIMO y no la suma: los platos se hacen a la vez, no en
     * fila. Sumar daría media hora para dos arepas y la pantalla de cocina
     * nunca marcaría nada como tarde.
     */
    public function estimatedPrepMinutes(array $prepMinutesByProduct): ?int
    {
        $known = [];

        foreach ($this->lines as $line) {
            $minutes = $prepMinutesByProduct[$line->productId] ?? null;

            if ($minutes !== null) {
                $known[] = (int) $minutes;
            }
        }

        return $known === [] ? null : max($known);
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function serviceType(): ServiceType
    {
        return $this->serviceType;
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function deliveryFee(): Money
    {
        return $this->deliveryFee;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function cancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function stampedAt(string $key): ?DateTimeImmutable
    {
        return $this->timestamps[$key] ?? null;
    }

    /** @return array<string, DateTimeImmutable|null> */
    public function timestamps(): array
    {
        return $this->timestamps;
    }

    private static function stampFor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Placed => 'placed_at',
            OrderStatus::Confirmed => 'confirmed_at',
            OrderStatus::Preparing => 'preparing_at',
            OrderStatus::Ready => 'ready_at',
            OrderStatus::OutForDelivery => 'out_for_delivery_at',
            OrderStatus::Delivered => 'delivered_at',
            OrderStatus::Cancelled => 'cancelled_at',
            OrderStatus::PendingPayment => 'placed_at',
        };
    }
}
