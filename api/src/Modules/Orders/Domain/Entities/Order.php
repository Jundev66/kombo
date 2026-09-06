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
 * An order.
 *
 * Plain PHP. The rules below have to hold identically from the portal, the bot,
 * the till and the kitchen screen; in a controller they hold only where
 * somebody remembered to call them.
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
            // Mobile payment is born awaiting the receipt; cash or till is born
            // already received. The only difference between the two ways in.
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
     * Moves to the next state and stamps the step's time, which is where "how
     * long to confirm" and "how long the kitchen takes" come from.
     */
    public function moveTo(OrderStatus $next, ?DateTimeImmutable $now = null): void
    {
        // Repeating the current step is NOT an error: two people tapping "Confirm"
        // at once cannot raise a red message mid-service.
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
     * Cancelling: the only path that skips the transition table, because in
     * real life a customer changes their mind at any moment. What it does not
     * skip is that a terminal order stays terminal.
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

    /** What the lines come to, without delivery. */
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
     * How long the kitchen should take. The MAXIMUM across lines, not the sum:
     * dishes are made at the same time, and summing would give half an hour for
     * two arepas.
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
