<?php

declare(strict_types=1);

use Modules\Orders\Domain\ValueObjects\OrderStatus;

/*
 * The whole transition table.
 *
 * Tested exhaustively because it is the rule the most places consult — portal,
 * till, bot, kitchen — and the one that shows worst when it fails: a delivered
 * order back on the griddle, or one that never reaches the kitchen.
 */

it('an order\'s normal path reaches delivered', function (): void {
    $path = [
        OrderStatus::Placed,
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::Ready,
        OrderStatus::Delivered,
    ];

    for ($i = 0; $i < count($path) - 1; $i++) {
        expect($path[$i]->canMoveTo($path[$i + 1]))->toBeTrue();
    }
});

it('a delivery order passes through "on the way"', function (): void {
    expect(OrderStatus::Ready->canMoveTo(OrderStatus::OutForDelivery))->toBeTrue()
        ->and(OrderStatus::OutForDelivery->canMoveTo(OrderStatus::Delivered))->toBeTrue();
});

it('from "ready" it can be handed over directly, without going out', function (): void {
    // The counter case: the customer is standing there waiting.
    expect(OrderStatus::Ready->canMoveTo(OrderStatus::Delivered))->toBeTrue();
});

it('the kitchen cannot be skipped', function (): void {
    // Confirming and marking ready in one go would skip the griddle, leaving
    // somebody waiting for food nobody made.
    expect(OrderStatus::Confirmed->canMoveTo(OrderStatus::Ready))->toBeFalse()
        ->and(OrderStatus::Placed->canMoveTo(OrderStatus::Delivered))->toBeFalse();
});

it('there is no going back', function (): void {
    // A stray tap sending a delivered order back to "in the kitchen" gets the
    // food made twice. Real corrections are the manager's job: cancel and
    // re-take it.
    expect(OrderStatus::Ready->canMoveTo(OrderStatus::Preparing))->toBeFalse()
        ->and(OrderStatus::Confirmed->canMoveTo(OrderStatus::Placed))->toBeFalse();
});

it('delivered and cancelled are the end', function (): void {
    expect(OrderStatus::Delivered->isTerminal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(OrderStatus::Delivered->allowedNext())->toBe([])
        ->and(OrderStatus::Cancelled->allowedNext())->toBe([]);
});

it('can be cancelled from any live point', function (): void {
    // In real life a customer changes their mind at any moment.
    foreach (OrderStatus::cases() as $status) {
        if ($status->isTerminal()) {
            continue;
        }

        expect($status->canMoveTo(OrderStatus::Cancelled))->toBeTrue();
    }
});

it('knows when an order is in the kitchen', function (): void {
    // Used by the dashboard board and the kitchen screen, and they have to
    // agree: deciding separately, an order would appear on one and not the
    // other.
    expect(OrderStatus::Confirmed->isInKitchen())->toBeTrue()
        ->and(OrderStatus::Preparing->isInKitchen())->toBeTrue()
        ->and(OrderStatus::Ready->isInKitchen())->toBeFalse()
        ->and(OrderStatus::Placed->isInKitchen())->toBeFalse();
});

it('speaks without jargon', function (): void {
    // What a person sees cannot say "pending_payment".
    expect(OrderStatus::PendingPayment->label())->toBe('Esperando el pago')
        ->and(OrderStatus::Preparing->label())->toBe('En la cocina');
});
