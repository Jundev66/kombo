<?php

declare(strict_types=1);

use Modules\Orders\Domain\Entities\Order;
use Modules\Orders\Domain\Exceptions\EmptyOrder;
use Modules\Orders\Domain\Exceptions\InvalidQuantity;
use Modules\Orders\Domain\Exceptions\InvalidTransition;
use Modules\Orders\Domain\ValueObjects\OrderLine;
use Modules\Orders\Domain\ValueObjects\OrderLineModifier;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Shared\Domain\ValueObjects\Money;

function aLine(int $price = 300, int $quantity = 1, array $modifiers = []): OrderLine
{
    return new OrderLine(
        productId: 'p-1',
        productName: 'Reina Pepiada',
        unitPrice: Money::fromCents($price),
        quantity: $quantity,
        modifiers: $modifiers,
    );
}

function anOrder(array $lines = [], ?Money $delivery = null, bool $awaitingPayment = false): Order
{
    return Order::place(
        id: 'o-1',
        serviceType: ServiceType::Takeaway,
        lines: $lines === [] ? [aLine()] : $lines,
        deliveryFee: $delivery,
        awaitingPayment: $awaitingPayment,
    );
}

it('an order with nothing to charge for is not an order', function (): void {
    expect(fn () => anOrder([]))->not->toThrow(EmptyOrder::class);

    expect(fn () => Order::place('o-1', ServiceType::Takeaway, []))
        ->toThrow(EmptyOrder::class);
});

it('a line has to carry at least one', function (): void {
    expect(fn () => aLine(quantity: 0))->toThrow(InvalidQuantity::class);
});

it('add-ons are charged PER UNIT, not per order', function (): void {
    // Two burgers with extra cheese carry the extra twice. The formula
    // "price × quantity + add-ons" charges for one and gives the other away —
    // and nobody notices until the month's margin does not add up.
    $line = aLine(price: 300, quantity: 2, modifiers: [
        new OrderLineModifier('m-1', 'Extra queso', Money::fromCents(50)),
    ]);

    expect($line->total()->cents)->toBe(700);
});

it('an add-on can take money off', function (): void {
    $line = aLine(price: 300, modifiers: [
        new OrderLineModifier('m-1', 'Sin queso', Money::fromCents(-50)),
    ]);

    expect($line->total()->cents)->toBe(250);
});

it('add-ons come out as resolved text for the ticket', function (): void {
    // The kitchen reads "NO ONION · EXTRA CHEESE", not ids to look up.
    $line = aLine(modifiers: [
        new OrderLineModifier('m-1', 'Sin cebolla', Money::zero()),
        new OrderLineModifier('m-2', 'Extra queso', Money::fromCents(50)),
    ]);

    expect($line->modifiersText())->toBe('Sin cebolla · Extra queso');
});

it('the total adds the lines and the delivery fee', function (): void {
    $order = anOrder(
        [aLine(300, 2), aLine(150)],
        delivery: Money::fromCents(200),
    );

    expect($order->subtotal()->cents)->toBe(750)
        ->and($order->total()->cents)->toBe(950);
});

it('with mobile payment the order is born awaiting the receipt', function (): void {
    expect(anOrder(awaitingPayment: true)->status())->toBe(OrderStatus::PendingPayment)
        ->and(anOrder()->status())->toBe(OrderStatus::Placed);
});

it('confirming stamps the time, which is where the durations come from', function (): void {
    $order = anOrder();
    $order->moveTo(OrderStatus::Confirmed, new DateTimeImmutable('2026-03-01 12:30:00'));

    expect($order->stampedAt('confirmed_at')?->format('H:i'))->toBe('12:30');
});

it('repeating the step it is already on is not an error', function (): void {
    // Two people tapping "Confirm" at once cannot raise a red message in the
    // middle of service.
    $order = anOrder();
    $order->moveTo(OrderStatus::Confirmed);

    expect(fn () => $order->moveTo(OrderStatus::Confirmed))->not->toThrow(InvalidTransition::class);
    expect($order->status())->toBe(OrderStatus::Confirmed);
});

it('does not allow skipping the kitchen', function (): void {
    $order = anOrder();
    $order->moveTo(OrderStatus::Confirmed);

    expect(fn () => $order->moveTo(OrderStatus::Ready))->toThrow(InvalidTransition::class);
});

it('cancelling skips the table, because a customer changes their mind whenever', function (): void {
    $order = anOrder();
    $order->moveTo(OrderStatus::Confirmed);
    $order->moveTo(OrderStatus::Preparing);

    $order->cancel('El cliente se arrepintió');

    expect($order->status())->toBe(OrderStatus::Cancelled)
        ->and($order->cancellationReason())->toBe('El cliente se arrepintió');
});

it('a delivered order does not come back to life', function (): void {
    $order = anOrder();
    $order->moveTo(OrderStatus::Confirmed);
    $order->moveTo(OrderStatus::Preparing);
    $order->moveTo(OrderStatus::Ready);
    $order->moveTo(OrderStatus::Delivered);

    expect(fn () => $order->cancel('Ups'))->toThrow(InvalidTransition::class);
});

it('kitchen time is the MAXIMUM, not the sum', function (): void {
    // Dishes are made at the same time, not one after another. Summing would
    // give half an hour for two arepas and nothing would ever look late.
    $order = anOrder([
        new OrderLine('p-1', 'Arepa', Money::fromCents(300), 1),
        new OrderLine('p-2', 'Parrilla', Money::fromCents(900), 1),
    ]);

    expect($order->estimatedPrepMinutes(['p-1' => 8, 'p-2' => 20]))->toBe(20);
});

it('with no known times it does not invent one', function (): void {
    expect(anOrder()->estimatedPrepMinutes([]))->toBeNull();
});
